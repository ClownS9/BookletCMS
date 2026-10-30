<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Booklet\Data\Blueprint;
use App\Booklet\Data\Collection;
use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use App\Booklet\Support\Menus;
use App\Booklet\Support\PageTree;
use App\Livewire\Concerns\Stamps;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Booklet\Data\Asset;
use App\Booklet\Support\AssetUploader;
use App\Support\Blocks;
use App\Support\Links;
use App\Support\Rows;
use App\Support\Conditions;

/**
 * The open spread for writing: the blueprint's main tab on the left page,
 * everything else in the margin notes on the right. Every save keeps an
 * edition (revision) and stamps the activity log.
 *
 * Worked out when asked (#[Computed]):
 *
 * @property-read Collection $model
 * @property-read Blueprint|null $blueprint
 * @property-read array{main: array, side: array} $fields
 * @property-read array $pickerAssets
 * @property-read int $editions
 * @property-read \Illuminate\Support\Collection<int, Entry> $shelfEntries
 * @property-read \Illuminate\Support\Collection<int, Entry> $linkedFrom
 */
#[Layout('components.layouts.admin')]
class EntryEditor extends Component
{
    use PageTitle;

    use WithFileUploads, Stamps;

    /** The image field the picture picker is open for, if any. */
    public ?string $picking = null;

    public string $pickerSearch = '';

    public $pickerUpload = null;

    /** Locked: the browser can't switch which entry (or chapter) this editor saves to. */
    #[\Livewire\Attributes\Locked]
    public string $collection;

    #[\Livewire\Attributes\Locked]
    public ?string $entryId = null;

    public ?string $blueprintHandle = null;

    /** Field values by handle, as the blueprint defines them. */
    public array $values = [];

    /** Terms fields edited as "food, city, evening". */
    public array $termInputs = [];

    public string $slug = '';

    public bool $slugTouched = false;

    public string $status = 'draft';

    /** "Y-m-d\TH:i" for the datetime-local input. */
    public ?string $date = null;

    public ?string $savedAt = null;

    /** Pages: the page a new one goes under ("Add sub-page"). */
    #[Url(except: null)]
    public ?string $parent = null;

    /** Email this post to subscribers when it goes live. */
    public bool $emailIt = true;

    /** Who it's credited to (a user id); editors and admins can change it. */
    public string $author = '';

    /** The series it's part of ("Kuih from scratch"), and its part number (see App\Support\Series). */
    public string $series = '';

    public ?string $seriesPart = null;

    /** When it comes down by itself ("Y-m-d\TH:i"), and where readers go then (see App\Support\Takedowns). */
    public ?string $takedown = null;

    public string $takedownTo = '';

    /** Who can read it on the site: everyone, signed-in readers, or readers in some groups (see App\Support\Members). */
    public string $openTo = 'everyone';

    /** @var list<string> the groups, when it's for some groups */
    public array $openToGroups = [];

    public function mount(string $collection, ?string $entry = null): void
    {
        $model = Booklet::collections()->find($collection);
        abort_unless($model !== null, 404);
        abort_unless(request()->user()->canOpen($collection), 403, 'Your role doesn’t open this chapter.');

        $this->collection = $collection;

        if ($entry) {
            $existing = Booklet::entries()->find($entry);
            abort_unless($existing && $existing->collectionHandle() === $collection, 404);
            abort_unless($this->canEdit($existing), 403, 'Writers can only open their own entries.');

            $this->entryId = $existing->id();
            $this->blueprintHandle = $existing->blueprint()?->handle();
            $this->slug = (string) $existing->slug();
            $this->slugTouched = true;
            $this->status = $existing->status();
            $this->date = $existing->date()?->format('Y-m-d\TH:i');
            $this->savedAt = $existing->lastModified();
            $this->emailIt = ! $existing->get('no_email');
            $this->readTakedown($existing);
            $this->readSeries($existing);
            $this->fill($this->valuesFrom($existing->data()));

            return;
        }

        $this->blueprintHandle = $model->blueprints()->first()?->handle();
        $this->date = $model->dated() ? now()->format('Y-m-d\TH:i') : null;

        // "Plan a post for this day" from the calendar.
        $planned = (string) request()->query('date', '');
        if ($model->dated() && preg_match('/^\d{4}-\d{2}-\d{2}$/', $planned)) {
            $this->date = $planned.'T09:00';
        }

        $this->author = (string) request()->user()?->id; // whoever starts it, unless an editor credits someone else
        $this->fill($this->valuesFrom([]));
    }

    // Blueprint ------------------------------------------------------------

    #[Computed]
    public function model(): Collection
    {
        return Booklet::collections()->findOrFail($this->collection);
    }

    #[Computed]
    public function blueprint(): ?Blueprint
    {
        return $this->blueprintHandle
            ? Booklet::blueprints()->findIn("collections.{$this->collection}", $this->blueprintHandle)
            : null;
    }

    /**
     * Fields split into the left page ("main" tab) and the margin notes
     * (every other tab), with fieldset imports expanded.
     *
     * @return array{main: array, side: array}
     */
    #[Computed]
    public function fields(): array
    {
        $groups = ['main' => [], 'side' => []];

        if (! $this->blueprint) {
            // No blueprint yet: a title and a body still let people write.
            $groups['main'] = [
                $this->normalize(['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'validate' => ['required']]]),
                $this->normalize(['handle' => 'content', 'field' => ['type' => 'markdown', 'display' => 'Body']]),
            ];

            return $groups;
        }

        foreach ($this->blueprint->tabs() as $tabHandle => $tab) {
            $group = $tabHandle === 'main' ? 'main' : 'side';

            foreach ((array) ($tab['sections'] ?? []) as $section) {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    foreach ($this->expand($field) as $expanded) {
                        $groups[$group][] = $this->normalize($expanded);
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * @return array<int, array>
     */
    protected function allFields(): array
    {
        return array_merge($this->fields['main'], $this->fields['side']);
    }

    /**
     * Turn `- import: seo` into the fieldset's own fields.
     */
    protected function expand(array $field): array
    {
        if (! isset($field['import'])) {
            return [$field];
        }

        $fieldset = Booklet::fieldsets()->find($field['import']);
        $prefix = (string) ($field['prefix'] ?? '');

        return collect((array) $fieldset?->get('fields', []))
            ->map(fn ($f) => isset($f['handle']) ? ['handle' => $prefix.$f['handle']] + $f : $f)
            ->all();
    }

    protected function normalize(array $field): array
    {
        $config = (array) ($field['field'] ?? []);
        $handle = (string) $field['handle'];
        $validate = (array) ($config['validate'] ?? []);

        return [
            'handle' => $handle,
            'type' => $config['type'] ?? 'text',
            'display' => $config['display'] ?? Str::headline($handle),
            'instructions' => $config['instructions'] ?? null,
            'required' => in_array('required', $validate, true),
            'validate' => $validate,
            'options' => (array) ($config['options'] ?? []),
            'default' => $config['default'] ?? null,
            'taxonomy' => ((array) ($config['taxonomies'] ?? []))[0] ?? null,
            'max_items' => $config['max_items'] ?? $config['max_files'] ?? null,
            'container' => $config['container'] ?? 'media',
            'sets' => array_values(array_filter((array) ($config['sets'] ?? []), 'is_string')),
            'collections' => Links::collectionsOf($config),
            'columns' => ($config['type'] ?? null) === 'grid' ? Rows::columns($config) : [],
            'conditions' => array_intersect_key($config, Conditions::KEYS),
            'always_save' => (bool) ($config['always_save'] ?? false),
        ];
    }

    // Values ---------------------------------------------------------------

    /**
     * @return array{values: array, termInputs: array}
     */
    protected function valuesFrom(array $data): array
    {
        $values = [];
        $terms = [];

        foreach ($this->allFields() as $field) {
            $value = $data[$field['handle']] ?? $field['default'];

            match ($field['type']) {
                'terms' => $terms[$field['handle']] = implode(', ', array_map(
                    fn ($slug) => Booklet::terms()->findBySlug((string) $field['taxonomy'], (string) $slug)?->title() ?? $slug,
                    (array) $value,
                )),
                'toggle' => $values[$field['handle']] = (bool) $value,
                'assets' => $values[$field['handle']] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value,
                'blocks' => $values[$field['handle']] = Blocks::forEditor($value),
                'entries' => $values[$field['handle']] = array_values(array_filter(is_array($value) ? $value : [], fn ($id) => Links::looksLikeId($id))),
                'grid' => $values[$field['handle']] = Rows::forEditor($value, $field['columns']),
                default => $values[$field['handle']] = is_scalar($value) || $value === null ? (string) $value : $value,
            };
        }

        return ['values' => $values, 'termInputs' => $terms];
    }

    public function updatedValues(mixed $value, string $key): void
    {
        if ($key === 'title' && ! $this->slugTouched) {
            $this->slug = Str::slug((string) $value);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugTouched = true;
        $this->slug = Str::slug($this->slug);
    }

    // Picture picker -----------------------------------------------------

    public function openPicker(string $handle): void
    {
        $this->picking = $handle;
        $this->pickerSearch = '';
    }

    public function closePicker(): void
    {
        $this->picking = null;
        $this->pickerUpload = null;
    }

    public function choose(string $path): void
    {
        if ($this->picking !== null && str_contains($this->picking, '.')) {
            $this->chooseForBlock($path);

            return;
        }
        if ($this->picking !== null) {
            $this->values[$this->picking] = $path;
        }

        $this->closePicker();
    }

    public function clearPicture(string $handle): void
    {
        $this->values[$handle] = '';
    }

    protected function pickerContainer(): string
    {
        if (str_contains((string) $this->picking, '.')) {
            return 'media'; // a cut-out's picture
        }
        $field = collect($this->allFields())->firstWhere('handle', $this->picking);

        return (string) ($field['container'] ?? 'media');
    }

    public function updatedPickerUpload(): void
    {
        abort_unless(request()->user()->canChangeMedia(), 403);
        $this->validate(['pickerUpload' => ['file', 'max:102400', 'mimes:'.AssetUploader::MIMES]], [], ['pickerUpload' => 'file']);

        $asset = (new AssetUploader($this->pickerContainer()))->store($this->pickerUpload, 'uploads');
        $this->choose($asset->path());
    }

    #[Computed]
    public function pickerAssets(): array
    {
        if ($this->picking === null) {
            return [];
        }

        $container = $this->pickerContainer();
        $disk = \App\Support\Disk::of(Booklet::assetContainers()->find($container)?->attribute('disk') ?? 'public');
        $needle = Str::lower($this->pickerSearch);

        return Booklet::assets()->inContainer($container)
            ->filter(fn (Asset $a) => $needle === '' || Str::contains(Str::lower($a->path().' '.$a->get('alt')), $needle))
            ->sortByDesc(fn (Asset $a) => $a->get('last_modified'))
            ->take(60)
            ->map(fn (Asset $a) => [
                'path' => $a->path(),
                'name' => $a->attribute('basename'),
                'image' => $a->isImage(),
                'url' => $disk->url($a->path()),
                'srcset' => \App\Support\Pictures::srcset($a->path(), $a->container()),
                'focus' => \App\Support\Focus::of($a),
                'ext' => strtoupper((string) $a->attribute('extension')),
            ])
            ->values()
            ->all();
    }

    /**
     * Preview URLs for the picture fields currently filled in.
     */
    protected function pictureUrls(): array
    {
        $urls = [];

        foreach ($this->allFields() as $field) {
            $path = $this->values[$field['handle']] ?? '';
            if ($field['type'] === 'assets' && $path !== '') {
                $disk = Booklet::assetContainers()->find($field['container'])?->attribute('disk') ?? 'public';
                $urls[$field['handle']] = \App\Support\Disk::of($disk)->url($path);
            }
        }

        return $urls;
    }

    // Blocks: cut-outs tucked into the page ---------------------------------------------------

    /** The last cut-out taken out, so it can be put back. */
    public ?array $removedBlock = null;

    /** The blocks field with this handle (or a 404 for anything else). */
    protected function blocksField(string $handle): array
    {
        $field = collect($this->allFields())->firstWhere('handle', $handle);
        abort_unless(is_array($field) && $field['type'] === 'blocks', 404);
        if (! is_array($this->values[$handle] ?? null)) {
            $this->values[$handle] = [];
        }

        return $field;
    }

    /** Can an admin give this chapter cut-outs from here (it has a blueprint but no blocks field yet)? */
    protected function canAddCutouts(): bool
    {
        return (bool) request()->user()?->isAdmin()
            && $this->blueprint !== null
            && ! collect($this->allFields())->contains('type', 'blocks');
    }

    /**
     * Give this chapter's blueprint a Cut-outs field, right from the editor,
     * so nobody has to go looking in Collections for it.
     */
    public function addCutouts(): void
    {
        abort_unless($this->canAddCutouts(), 403);

        $taken = collect($this->allFields())->pluck('handle');
        $handle = $taken->contains('blocks') ? 'cut_outs' : 'blocks';
        $this->blueprint->addField($handle, [
            'type' => 'blocks',
            'display' => 'Cut-outs',
            'instructions' => 'Quotes, pictures, buttons and more, after the body.',
        ])->save();

        ActivityLog::record('added cut-outs to', 'settings', 'collection', $this->collection, $this->model->title());
        unset($this->blueprint, $this->fields);
        $this->values[$handle] = [];
        $this->stamp(__('Cut-outs added to every :one. Tuck one in below.', ['one' => Str::lower(Str::singular($this->model->title()))]));
    }

    /** Tuck in a new cut-out, at the end or before the one at $at. */
    public function addBlock(string $handle, string $type, ?int $at = null): void
    {
        $field = $this->blocksField($handle);
        abort_unless(array_key_exists($type, Blocks::kindsFor($field)), 422);
        if (count($this->values[$handle]) >= Blocks::MAX_BLOCKS) {
            $this->stamp(__('That’s as many cut-outs as one field holds (:max).', ['max' => Blocks::MAX_BLOCKS]));

            return;
        }

        $blocks = $this->values[$handle];
        $at = $at === null ? count($blocks) : max(0, min(count($blocks), $at));
        array_splice($blocks, $at, 0, [Blocks::make($type)]);
        $this->values[$handle] = $blocks;
    }

    public function moveBlock(string $handle, int $from, int $to): void
    {
        $this->blocksField($handle);
        $blocks = $this->values[$handle];
        if (! isset($blocks[$from]) || $from === $to) {
            return;
        }
        $to = max(0, min(count($blocks) - 1, $to));
        [$moved] = array_splice($blocks, $from, 1);
        array_splice($blocks, $to, 0, [$moved]);
        $this->values[$handle] = $blocks;
    }

    public function duplicateBlock(string $handle, int $index): void
    {
        $this->blocksField($handle);
        $blocks = $this->values[$handle];
        if (! isset($blocks[$index]) || count($blocks) >= Blocks::MAX_BLOCKS) {
            return;
        }
        array_splice($blocks, $index + 1, 0, [['id' => Blocks::id()] + $blocks[$index]]);
        $this->values[$handle] = $blocks;
    }

    /** Hidden cut-outs stay in the page's editor but aren't shown to readers. */
    public function toggleBlock(string $handle, int $index): void
    {
        $this->blocksField($handle);
        if (isset($this->values[$handle][$index])) {
            $this->values[$handle][$index]['enabled'] = ! ($this->values[$handle][$index]['enabled'] ?? true);
        }
    }

    public function removeBlock(string $handle, int $index): void
    {
        $this->blocksField($handle);
        $blocks = $this->values[$handle];
        if (! isset($blocks[$index])) {
            return;
        }
        [$gone] = array_splice($blocks, $index, 1);
        $this->values[$handle] = $blocks;
        $this->removedBlock = ['handle' => $handle, 'index' => $index, 'block' => $gone];
    }

    public function undoRemoveBlock(): void
    {
        $removed = $this->removedBlock;
        $this->removedBlock = null;
        if (! $removed) {
            return;
        }
        $this->blocksField($removed['handle']);
        $blocks = $this->values[$removed['handle']];
        array_splice($blocks, min($removed['index'], count($blocks)), 0, [$removed['block']]);
        $this->values[$removed['handle']] = $blocks;
    }

    /** Choose a picture for one of a cut-out's picture fields. */
    public function openBlockPicker(string $handle, int $index, string $field): void
    {
        $this->blocksField($handle);
        abort_unless(isset($this->values[$handle][$index]) && preg_match('/^[a-z][a-z0-9_]*$/', $field), 404);
        $this->picking = "{$handle}.{$index}.{$field}";
        $this->pickerSearch = '';
    }

    protected function chooseForBlock(string $path): void
    {
        [$handle, $index, $name] = explode('.', (string) $this->picking, 3);
        $block = $this->values[$handle][(int) $index] ?? null;
        $field = collect($this->allFields())->firstWhere('handle', $handle);
        $type = ($field['type'] ?? null) === 'grid'
            ? (($field['columns'][$name]['type'] ?? null) === 'picture' ? 'picture' : null)
            : (Blocks::kinds()[$block['type'] ?? '']['fields'][$name]['type'] ?? null);

        if (is_array($block) && in_array($type, ['picture', 'pictures'], true)) {
            $current = array_values(array_filter((array) ($block[$name] ?? []), 'is_string'));
            $this->values[$handle][(int) $index][$name] = $type === 'picture'
                ? [$path]
                : array_slice(array_values(array_unique([...$current, $path])), 0, Blocks::MAX_PICTURES);
        }

        // A few pictures together: the picker stays open to add more.
        if ($type !== 'pictures') {
            $this->closePicker();
        }
    }

    // Rows (Statamic's Grid) ------------------------------------------------------------------

    /** The last row taken out, so it can be put back. */
    public ?array $removedRow = null;

    protected function gridField(string $handle): array
    {
        $field = collect($this->allFields())->firstWhere('handle', $handle);
        abort_unless(is_array($field) && $field['type'] === 'grid', 404);
        if (! is_array($this->values[$handle] ?? null)) {
            $this->values[$handle] = [];
        }

        return $field;
    }

    public function addRow(string $handle): void
    {
        $field = $this->gridField($handle);
        if (count($this->values[$handle]) >= Rows::MAX_ROWS) {
            return;
        }
        $this->values[$handle][] = Rows::blank($field['columns']);
    }

    public function moveRow(string $handle, int $from, int $to): void
    {
        $this->gridField($handle);
        $rows = $this->values[$handle];
        if (! isset($rows[$from]) || $from === $to) {
            return;
        }
        [$moved] = array_splice($rows, $from, 1);
        array_splice($rows, max(0, min(count($rows), $to)), 0, [$moved]);
        $this->values[$handle] = $rows;
    }

    public function removeRow(string $handle, int $index): void
    {
        $this->gridField($handle);
        $rows = $this->values[$handle];
        if (! isset($rows[$index])) {
            return;
        }
        [$gone] = array_splice($rows, $index, 1);
        $this->values[$handle] = $rows;
        $this->removedRow = ['handle' => $handle, 'index' => $index, 'row' => $gone];
    }

    public function undoRemoveRow(): void
    {
        $removed = $this->removedRow;
        $this->removedRow = null;
        if (! $removed) {
            return;
        }
        $this->gridField($removed['handle']);
        $rows = $this->values[$removed['handle']];
        array_splice($rows, min((int) $removed['index'], count($rows)), 0, [$removed['row']]);
        $this->values[$removed['handle']] = $rows;
    }

    public function openRowPicker(string $handle, int $index, string $column): void
    {
        $field = $this->gridField($handle);
        abort_unless(isset($this->values[$handle][$index]) && ($field['columns'][$column]['type'] ?? null) === 'picture', 404);
        $this->picking = "{$handle}.{$index}.{$column}";
        $this->pickerSearch = '';
    }

    public function clearRowPicture(string $handle, int $index, string $column): void
    {
        $this->gridField($handle);
        if (isset($this->values[$handle][$index])) {
            $this->values[$handle][$index][$column] = [];
        }
    }

    // Linked entries: the shelf to pick from ---------------------------------------------------

    /** Where the shelf is choosing for: a field ("related") or a cut-out's ("blocks.2.entries"). */
    public ?string $linking = null;

    public string $linkSearch = '';

    /**
     * What a link target points at: its config (where from, how many) and its
     * current ids. Anything that isn't a linked-entries field or cut-out is a 404.
     *
     * @return array{field: array, ids: list<string>}
     */
    protected function linkTarget(string $target): array
    {
        $parts = explode('.', $target);
        $field = collect($this->allFields())->firstWhere('handle', $parts[0]);

        if (count($parts) === 1 && is_array($field) && $field['type'] === 'entries') {
            $config = $field;
        } elseif (count($parts) === 3 && is_array($field) && $field['type'] === 'blocks' && ctype_digit($parts[1])) {
            $block = $this->values[$parts[0]][(int) $parts[1]] ?? null;
            $config = Blocks::kinds()[$block['type'] ?? '']['fields'][$parts[2]] ?? null;
            abort_unless(is_array($config) && $config['type'] === 'entries', 404);
        } else {
            abort(404);
        }

        $ids = data_get($this->values, $target);

        return ['field' => $config, 'ids' => array_values(array_filter(is_array($ids) ? $ids : [], fn ($id) => Links::looksLikeId($id)))];
    }

    public function openShelf(string $target): void
    {
        $this->linkTarget($target);
        $this->linking = $target;
        $this->linkSearch = '';
    }

    public function closeShelf(): void
    {
        $this->linking = null;
    }

    public function linkEntry(string $id): void
    {
        if ($this->linking === null) {
            return;
        }
        ['field' => $field, 'ids' => $ids] = $this->linkTarget($this->linking);
        $max = (int) ($field['max_items'] ?? 0) ?: Links::MAX;
        $entry = Booklet::entries()->find($id);
        $allowed = Links::collectionsOf($field);

        if ($entry && $id !== $this->entryId && ! in_array($id, $ids, true) && count($ids) < $max
            && ($allowed === [] || in_array($entry->collectionHandle(), $allowed, true))) {
            $ids[] = $id;
            data_set($this->values, $this->linking, $ids);
        }
        if (count($ids) >= $max) {
            $this->closeShelf();
        }
    }

    public function unlinkEntry(string $target, string $id): void
    {
        $ids = $this->linkTarget($target)['ids'];
        data_set($this->values, $target, array_values(array_filter($ids, fn ($i) => $i !== $id)));
    }

    public function moveLink(string $target, int $from, int $to): void
    {
        $ids = $this->linkTarget($target)['ids'];
        if (! isset($ids[$from])) {
            return;
        }
        [$moved] = array_splice($ids, $from, 1);
        array_splice($ids, max(0, min(count($ids), $to)), 0, [$moved]);
        data_set($this->values, $target, $ids);
    }

    /** @return \Illuminate\Support\Collection<int, Entry> */
    #[Computed]
    public function shelfEntries(): \Illuminate\Support\Collection
    {
        if ($this->linking === null) {
            return collect();
        }
        ['field' => $field, 'ids' => $ids] = $this->linkTarget($this->linking);

        return Links::shelf($this->linkSearch, $field, array_filter([...$ids, $this->entryId]))
            ->filter(fn (Entry $e) => request()->user()->canOpen($e->collectionHandle()))->values();
    }

    /**
     * Entries that link to this one.
     *
     * @return \Illuminate\Support\Collection<int, Entry>
     */
    #[Computed]
    public function linkedFrom(): \Illuminate\Support\Collection
    {
        $entry = $this->entryId ? Booklet::entries()->find($this->entryId) : null;

        return $entry ? Links::to($entry) : collect();
    }

    /** Is the picker open for a cut-out that holds several pictures? */
    protected function pickingMany(): bool
    {
        if (! str_contains((string) $this->picking, '.')) {
            return false;
        }
        [$handle, $index, $name] = explode('.', (string) $this->picking, 3);
        $type = (string) ($this->values[$handle][(int) $index]['type'] ?? '');

        return (Blocks::kinds()[$type]['fields'][$name]['type'] ?? null) === 'pictures';
    }

    public function removeBlockPicture(string $handle, int $index, string $field, string $path): void
    {
        $this->blocksField($handle);
        if (isset($this->values[$handle][$index][$field]) && is_array($this->values[$handle][$index][$field])) {
            $this->values[$handle][$index][$field] = array_values(array_filter($this->values[$handle][$index][$field], fn ($p) => $p !== $path));
        }
    }

    public function toggle(string $handle): void
    {
        $this->values[$handle] = ! ($this->values[$handle] ?? false);
    }

    /**
     * Admins and editors can do anything; writers only their own entries,
     * and only as drafts.
     */
    protected function canPublish(): bool
    {
        return request()->user()->canPublishIn($this->collection);
    }

    protected function canEdit(Entry $entry): bool
    {
        return $this->canPublish() || (string) $entry->get('author') === (string) request()->user()?->id;
    }

    public function setStatus(string $status): void
    {
        if ($status !== 'draft' && ! $this->canPublish()) {
            return;
        }

        $this->status = $status;

        if ($status !== 'draft' && ! $this->date) {
            $this->date = now()->format('Y-m-d\TH:i');
        }
        // A page has no date of its own: scheduling one suggests tomorrow morning.
        if ($status === 'scheduled' && ! $this->model->dated()
            && (! $this->date || rescue(fn () => ! Carbon::parse($this->date)->isFuture(), true, false))) {
            $this->date = now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
        }
    }

    /** Every field's value as the conditions read it (tags as typed). */
    protected function conditionValues(): array
    {
        return array_merge($this->values, $this->termInputs);
    }

    /**
     * Fields hidden right now by show-this-field-when rules.
     *
     * @return list<string>
     */
    protected function hiddenFields(): array
    {
        $values = $this->conditionValues();

        return collect($this->allFields())
            ->filter(fn ($f) => $f['conditions'] !== [] && ! Conditions::passes($f['conditions'], $values))
            ->pluck('handle')->values()->all();
    }

    /** Fields other fields' rules look at: these update as soon as they change. */
    protected function watchedFields(): array
    {
        return collect($this->allFields())->flatMap(fn ($f) => Conditions::watches($f['conditions']))->unique()->values()->all();
    }

    protected function rules(): array
    {
        $rules = [
            'slug' => ['nullable', 'alpha_dash', 'max:200'],
            'status' => ['required', 'in:draft,scheduled,published'],
            'date' => [$this->status === 'scheduled' ? 'required' : 'nullable', 'date',
                ...($this->status === 'scheduled' && ! $this->model->dated() ? ['after:now'] : [])],
            'series' => ['nullable', 'string', 'max:120'],
            'seriesPart' => ['nullable', 'integer', 'min:1', 'max:999'],
            'takedown' => ['nullable', 'date', 'after:now', ...($this->status === 'scheduled' && $this->date ? ['after:date'] : []), function ($attribute, $value, $fail) {
                if ($value && $this->entryId && (string) app(\App\Booklet\Site::class)->setting('homepage', 'posts') === $this->entryId) {
                    $fail('This is the home page, so it can’t come down by itself. Choose another home page first (Settings).');
                }
            }],
            'takedownTo' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (trim((string) $value) !== '' && ! \App\Support\Takedowns::safeTo(trim((string) $value))) {
                    $fail('Use an address like /offers or https://example.com.');
                }
            }],
        ];

        $hidden = $this->hiddenFields();

        foreach ($this->allFields() as $field) {
            $key = $field['type'] === 'terms' ? "termInputs.{$field['handle']}" : "values.{$field['handle']}";
            if (in_array($field['handle'], $hidden, true)) {
                $rules[$key] = ['nullable']; // not shown, so not asked for

                continue;
            }
            $fieldRules = array_values(array_filter($field['validate'], 'is_string'));

            if (! in_array('required', $fieldRules, true)) {
                array_unshift($fieldRules, 'nullable');
            }
            if (in_array($field['type'], ['blocks', 'entries', 'grid'], true)) {
                $max = match ($field['type']) { 'blocks' => Blocks::MAX_BLOCKS, 'grid' => Rows::MAX_ROWS, default => (int) ($field['max_items'] ?: Links::MAX) };
                $fieldRules = [...array_intersect($fieldRules, ['required', 'nullable']), 'array', 'max:'.$max];
            }

            $rules[$key] = $fieldRules;
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'takedown.after' => $this->status === 'scheduled' && $this->date && rescue(fn () => Carbon::parse((string) $this->takedown)->isFuture(), false, false)
                ? 'It has to come down after it goes out.' : 'Choose a time that hasn’t been yet.',
            'takedown.date' => 'Choose a day and a time.',
            'date.after' => 'Choose a time that hasn’t passed yet.',
        ];
    }

    /** What's set for coming down, from the saved entry. */
    protected function readTakedown(Entry $entry): void
    {
        $this->takedown = \App\Support\Takedowns::at($entry)?->format('Y-m-d\TH:i');
        $this->takedownTo = (string) $entry->get('takedown_to', '');
    }

    /** Clear the date it comes down (the button beside it). */
    protected function readSeries(Entry $entry): void
    {
        $this->author = (string) ($entry->get('author') ?? '');
        $rule = \App\Support\Members::rule($entry);
        $this->openTo = $rule['who'];
        $this->openToGroups = $rule['groups'];
        $this->series = (string) \App\Support\Series::name($entry);
        $this->seriesPart = ($n = \App\Support\Series::partNumber($entry)) ? (string) $n : null;
    }

    public function clearTakedown(): void
    {
        $this->takedown = null;
        $this->takedownTo = '';
        $this->resetErrorBag(['takedown', 'takedownTo']);
    }

    protected function validationAttributes(): array
    {
        $names = ['takedown' => 'the date it comes down', 'takedownTo' => 'where readers go', 'series' => __('the series'), 'seriesPart' => __('the part number')];

        foreach ($this->allFields() as $field) {
            $prefix = $field['type'] === 'terms' ? 'termInputs' : 'values';
            $names["{$prefix}.{$field['handle']}"] = Str::lower($field['display']);
        }

        return $names;
    }

    /**
     * Data to store, from the form values (terms become slugs, creating new ones).
     */
    protected function dataFromValues(): array
    {
        $data = [];
        $hidden = $this->hiddenFields();

        foreach ($this->allFields() as $field) {
            $handle = $field['handle'];
            if (in_array($handle, $hidden, true) && ! $field['always_save']) {
                $data[$handle] = null; // hidden fields aren't kept (as in Statamic)

                continue;
            }

            $data[$handle] = match ($field['type']) {
                'terms' => $this->termSlugs((string) ($this->termInputs[$handle] ?? ''), $field),
                'toggle' => (bool) ($this->values[$handle] ?? false),
                'assets' => ($this->values[$handle] ?? '') === '' ? [] : [$this->values[$handle]],
                'blocks' => Blocks::clean($this->values[$handle] ?? []) ?: null,
                'entries' => Links::clean($this->values[$handle] ?? [], $field, $this->entryId) ?: null,
                'grid' => Rows::clean($this->values[$handle] ?? [], $field['columns']) ?: null,
                default => ($this->values[$handle] ?? '') === '' ? null : $this->values[$handle],
            };
        }

        return $data;
    }

    /**
     * The search result and share card, from what's in the form now (unsaved
     * changes included), for the "Search & sharing" card.
     */
    protected function searchCard(): ?array
    {
        return rescue(function () {
            $existing = $this->entryId ? Booklet::entries()->find($this->entryId) : null;
            $data = [];
            foreach ($this->allFields() as $field) {
                $handle = $field['handle'];
                $value = $this->values[$handle] ?? null;
                $data[$handle] = match ($field['type']) {
                    'terms' => $existing?->get($handle), // never made up while typing
                    'assets' => $value === '' || $value === null ? [] : (array) $value,
                    'blocks' => Blocks::clean((array) ($value ?? [])) ?: null,
                    'entries', 'grid' => $existing?->get($handle),
                    default => $value === '' ? null : $value,
                };
            }

            $entry = $existing
                ? (clone $existing)->setData(array_merge($existing->data(), $data))
                : Booklet::entries()->make(['collection' => $this->collection, 'slug' => $this->slug ?: Str::slug((string) ($this->values['title'] ?? ''))], $data);
            if ($this->date) {
                rescue(fn () => $entry->setAttribute('date', \Illuminate\Support\Carbon::parse($this->date)->format('Y-m-d H:i:s')), null, false);
            }

            $slug = $this->slug ?: Str::slug((string) ($this->values['title'] ?? '')) ?: 'new';
            $url = $existing && $existing->slug() === $this->slug
                ? app(\App\Booklet\Site::class)->url($existing)
                : url(rtrim(Str::before((string) $this->model->route(), '{'), '/').'/'.$slug);

            return \App\Support\SearchCard::for($entry, $url) + [
                'fields' => array_values(array_intersect(['meta_title', 'meta_description'], array_column($this->allFields(), 'handle'))),
            ];
        }, null, false);
    }

    /**
     * The categories and tags there are already, most used first, for the pickers
     * (so nobody has to remember how a tag was spelt).
     *
     * @return array<string, list<array{title: string, count: int}>> taxonomy => choices
     */
    protected function termChoices(): array
    {
        $taxonomies = collect($this->allFields())->where('type', 'terms')->pluck('taxonomy')->filter()->unique()->values();
        if ($taxonomies->isEmpty()) {
            return [];
        }
        $entries = rescue(fn () => Booklet::entries()->all(), collect(), false);
        $out = [];
        foreach ($taxonomies as $taxonomy) {
            $used = [];
            foreach ($entries as $entry) {
                foreach ((array) $entry->get((string) $taxonomy, []) as $slug) {
                    if (is_string($slug)) {
                        $used[$slug] = ($used[$slug] ?? 0) + 1;
                    }
                }
            }
            $out[$taxonomy] = rescue(fn () => Booklet::terms()->all(), collect(), false)
                ->filter(fn ($t) => $t->attribute('taxonomy') === $taxonomy)
                ->map(fn ($t) => ['title' => $t->title(), 'count' => $used[(string) $t->attribute('slug')] ?? 0])
                ->sort(fn ($a, $b) => [$b['count'], mb_strtolower($a['title'])] <=> [$a['count'], mb_strtolower($b['title'])])
                ->values()->all();
        }

        return $out;
    }

    /**
     * Boxes that can start from something already written: the search title
     * from the title, the search description from the standfirst (or the first
     * lines), the cover caption from the picture's description in Media.
     * Offered only while the box is empty and there's something to start from.
     *
     * @return array<string, string> handle => what the button says
     */
    protected function fills(): array
    {
        $out = [];
        foreach (array_keys(self::FILLS) as $handle) {
            if (trim((string) ($this->values[$handle] ?? '')) === '' && $this->fillFor($handle) !== '') {
                $out[$handle] = $handle === 'meta_description' && trim((string) ($this->values['excerpt'] ?? '')) === ''
                    ? __('Use the first lines') : __(self::FILLS[$handle]);
            }
        }

        return $out;
    }

    /** handle => the button's words */
    protected const FILLS = [
        'meta_title' => 'Use the title',
        'meta_description' => 'Use the standfirst',
        'cover_caption' => 'Use the picture’s description',
    ];

    protected function fillFor(string $handle): string
    {
        if (! array_key_exists($handle, $this->values)) {
            return '';
        }

        return match ($handle) {
            'meta_title' => Str::limit(trim((string) ($this->values['title'] ?? '')), 70, ''),
            'meta_description' => Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) (trim((string) ($this->values['excerpt'] ?? '')) !== ''
                ? $this->values['excerpt']
                : strip_tags((string) Str::markdown((string) ($this->values['content'] ?? ''), ['html_input' => 'strip']))))), 160),
            'cover_caption' => ($cover = (string) ($this->values['cover'] ?? '')) !== ''
                ? trim((string) (Booklet::assets()->find(['container' => 'media', 'path' => $cover])?->get('alt', '') ?? ''))
                : '',
            default => '',
        };
    }

    /** Fill an empty box from what it can start from (see fills()). */
    public function fillFrom(string $handle): void
    {
        if (! array_key_exists($handle, self::FILLS) || trim((string) ($this->values[$handle] ?? '')) !== '') {
            return;
        }
        $this->values[$handle] = $this->fillFor($handle);
    }

    /** Who a post can be credited to (editors and admins choose; writers write as themselves). */
    protected function people(): \Illuminate\Support\Collection
    {
        return \App\Models\User::query()->orderBy('name')->get(['id', 'name', 'byline', 'role']);
    }

    protected function termSlugs(string $input, array $field): array
    {
        $taxonomy = $field['taxonomy'];
        $slugs = [];

        foreach (array_filter(array_map('trim', explode(',', $input))) as $title) {
            $slug = Str::slug($title);

            if ($slug === '') {
                continue;
            }

            if ($taxonomy && ! Booklet::terms()->findBySlug($taxonomy, $slug)) {
                Booklet::terms()->make(['taxonomy' => $taxonomy, 'slug' => $slug], ['title' => $title])->save();
            }

            $slugs[] = $slug;
        }

        $slugs = array_values(array_unique($slugs));

        return $field['max_items'] ? array_slice($slugs, 0, (int) $field['max_items']) : $slugs;
    }

    // Saving ---------------------------------------------------------------

    public function save(): void
    {
        if (! $this->canPublish()) {
            $this->status = 'draft';
        }

        $this->validate();
        $this->persist(quiet: false);
    }

    /**
     * Saved by itself a few seconds after typing stops, for drafts only.
     * Published and scheduled entries wait for the Save button, so nothing
     * half-written goes live. Returns what happened, for the note by the toolbar.
     */
    public function autosave(): string
    {
        $existing = $this->entryId ? Booklet::entries()->find($this->entryId) : null;

        if ($existing && ($existing->published() || ! $this->canEdit($existing))) {
            return 'manual';
        }
        if ($this->status !== 'draft') {
            return 'manual'; // they've chosen to publish or schedule: that's a deliberate save
        }
        if (trim((string) ($this->values['title'] ?? '')) === '' && trim((string) ($this->values['content'] ?? '')) === '') {
            return 'empty';
        }

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException) {
            $this->resetErrorBag(); // don't nag while they type; Save shows the problems
            return 'invalid';
        }

        $this->persist(quiet: true);

        return 'saved';
    }

    protected function persist(bool $quiet): void
    {
        $isNew = $this->entryId === null;
        $entries = Booklet::entries();

        /** @var Entry $entry */
        $entry = $isNew
            ? $entries->make(['collection' => $this->collection])
            : $entries->findOrFail($this->entryId);
        $wasLive = ! $isNew && $entry->status() === 'published'; // read before any change below

        abort_if(! $isNew && ! $this->canEdit($entry), 403);

        $data = array_merge($entry->data(), $this->dataFromValues(), ['no_email' => $this->model->dated() && ! $this->emailIt ? true : null]);
        if ($this->model->dated()) {
            // A numbered series: its name, and (if given) which part this is.
            $name = trim(preg_replace('/\s+/', ' ', $this->series) ?? '');
            $data[\App\Support\Series::NAME] = $name !== '' ? $name : null;
            $data[\App\Support\Series::PART] = $name !== '' && is_numeric($this->seriesPart) && (int) $this->seriesPart > 0 ? (int) $this->seriesPart : null;
        }
        if ($this->canPublish()) {
            // Who can read it: only an editor or admin decides (it's like publishing it to fewer people).
            $groups = array_values(array_intersect(array_map('strval', $this->openToGroups), array_keys(\App\Models\Group::choices())));
            $who = isset(\App\Support\Members::OPTIONS[$this->openTo]) ? $this->openTo : 'everyone';
            if ($who === 'groups' && $groups === []) {
                $who = 'readers';
            }
            $data[\App\Support\Members::WHO] = $who === 'everyone' ? null : $who;
            $data[\App\Support\Members::GROUPS] = $who === 'groups' ? $groups : null;

            // Coming down by itself: only while it's out (or going out); a draft keeps nothing of it.
            $down = $this->status !== 'draft' && $this->takedown ? Carbon::parse($this->takedown, config('app.timezone')) : null;
            $data['takedown_at'] = $down?->format('Y-m-d H:i:s');
            $data['takedown_to'] = $down && trim($this->takedownTo) !== '' ? trim($this->takedownTo) : null;
            if ($this->status !== 'draft') {
                $data['taken_down_at'] = null; // out again
            }
        }
        if ($isNew) {
            $data['author'] = request()->user()?->id;
        }
        // Credited to someone else: only by an editor or admin, and only to someone in the book.
        if ($this->canPublish() && $this->author !== '' && \App\Models\User::whereKey($this->author)->exists()) {
            $data['author'] = (int) $this->author;
        }
        $data = array_filter($data, fn ($value) => $value !== null);

        $date = $this->date ? Carbon::parse($this->date) : null;
        if ($this->status === 'published' && (! $date || $date->isFuture())) {
            $date = $this->model->dated() ? now() : $date;
        }
        // A page keeps a date only while it's scheduled.
        if (! $this->model->dated() && $this->status !== 'scheduled') {
            $date = null;
        }

        $entry->setData($data)
            ->setAttribute('blueprint', $this->blueprintHandle)
            ->setAttribute('slug', $this->slug ?: Str::slug((string) ($data['title'] ?? '')) ?: null)
            ->setAttribute('published', $this->status !== 'draft')
            ->setAttribute('date', $date);

        $entry->save();
        if ($entry->status() === 'published' && ! $wasLive) {
            app(\App\Inserts\InsertManager::class)->fire('entry.published', $entry);
        }

        // Pages live in a tree: a new one goes at the top, or under its parent.
        if ($isNew && $this->model->structured()) {
            $parent = $this->parent && Booklet::entries()->find($this->parent) ? $this->parent : null;
            PageTree::for($this->collection)->place($entry->id(), $parent)->save();
            $entry = Booklet::entries()->find($entry->id()) ?? $entry;
        }

        $action = match (true) {
            $isNew && $this->status === 'draft' => 'started a draft',
            $this->status === 'scheduled' => 'scheduled',
            $this->status === 'published' && $isNew => 'published',
            default => 'edited',
        };

        // Who saved last, so others with it open hear about it.
        Cache::put('booklet.saved.'.$entry->id(), ['user' => request()->user()?->id, 'at' => now()->timestamp], 3600);

        // Autosaves keep History tidy: at most one edition every ten minutes.
        $snapKey = 'booklet.autosnap.'.$entry->id().'.'.request()->user()?->id;
        if (! $quiet || $isNew || ! Cache::has($snapKey)) {
            Booklet::revisions()->snapshot($entry->revisionKey(), $entry, $quiet && ! $isNew ? 'autosaved' : $action, (string) request()->user()?->id);
            if ($quiet) {
                Cache::put($snapKey, true, 600);
            }
        }

        // Published or scheduled by an editor: any review is over, and the writer hears.
        if ($this->status !== 'draft' && $this->canPublish()) {
            \App\Support\Reviews::settle($entry, request()->user());
        }

        $this->dispatch('entry-saved');
        if (! $quiet || $isNew) {
            ActivityLog::record($action, $this->status === 'draft' || $action === 'edited' ? 'edits' : 'publishing', 'entry', $entry->id(), $entry->title());
        }

        if ($isNew && $quiet) {
            // A new draft saved by itself: stay on the page (no jump while typing), just take its address.
            $this->entryId = $entry->id();
            $this->slug = (string) $entry->slug();
            $this->savedAt = now()->format('Y-m-d H:i:s');
            $url = route('admin.entries.edit', ['collection' => $this->collection, 'entry' => $entry->id()]);
            $this->js('history.replaceState(history.state, "", '.json_encode($url).')');

            return;
        }

        if ($isNew) {
            $this->stamp($this->status === 'draft' ? 'Draft saved.' : ['published' => __('Published.'), 'scheduled' => __('Scheduled.')][$this->status] ?? ucfirst($this->status).'.', afterRedirect: true);
            $this->redirectRoute('admin.entries.edit', ['collection' => $this->collection, 'entry' => $entry->id()], navigate: true);

            return;
        }

        $this->slug = (string) $entry->slug();
        $this->date = $entry->date()?->format('Y-m-d\TH:i');
        $this->status = $entry->status();
        $this->readTakedown($entry);
        $this->readSeries($entry);
        $this->savedAt = now()->format('Y-m-d H:i:s');
        unset($this->editions);
    }

    // Review before publishing ---------------------------------------------

    /** The writer's note to go with "Ask for review". */
    public string $reviewNote = '';

    /** The editor's note to go with "Send back". */
    public string $backNote = '';

    /** Save the words as they are, then ask an editor to review them. */
    public function askReview(): void
    {
        $entry = $this->entryId ? Booklet::entries()->find($this->entryId) : null;
        if (! $entry || $entry->published() || ! $this->canEdit($entry)) {
            return;
        }

        $this->validate(array_merge($this->rules(), ['reviewNote' => ['nullable', 'string', 'max:'.\App\Support\Reviews::NOTE_MAX]]), [], ['reviewNote' => 'note']);
        $this->status = 'draft';
        $this->persist(quiet: true);

        \App\Support\Reviews::ask(request()->user(), Booklet::entries()->find($this->entryId), $this->reviewNote);
        $this->reviewNote = '';
        $this->stamp('Sent for review. Editors have been told.');
    }

    public function withdrawReview(): void
    {
        $entry = $this->entryId ? Booklet::entries()->find($this->entryId) : null;
        if ($entry && $this->canEdit($entry)) {
            \App\Support\Reviews::withdraw(request()->user(), $entry);
            $this->stamp('Taken back. It’s an ordinary draft again.');
        }
    }

    /** An editor sends the draft back to its writer with what to change. */
    public function sendBack(): void
    {
        $this->resetErrorBag();
        abort_unless($this->canPublish(), 403);
        $entry = $this->entryId ? Booklet::entries()->find($this->entryId) : null;
        if (! $entry || \App\Support\Reviews::state($entry) !== 'waiting') {
            return;
        }

        $this->validate(['backNote' => ['required', 'string', 'max:'.\App\Support\Reviews::NOTE_MAX]], [
            'backNote.required' => 'Say what to change, so they know what to do next.',
        ], ['backNote' => 'note']);

        \App\Support\Reviews::sendBack(request()->user(), $entry, $this->backNote);
        $this->backNote = '';
        $this->dispatch('entry-saved'); // the team notes list shows the new note
        $this->stamp('Sent back with your note.');
    }

    public function delete(): void
    {
        $existing = $this->entryId ? Booklet::entries()->find($this->entryId) : null;
        abort_if($existing && ! $this->canEdit($existing), 403);

        if (! $this->entryId) {
            return;
        }

        $entry = Booklet::entries()->find($this->entryId);

        // Into the bin for 30 days (out of the page tree and menus too), with Undo.
        $binned = $entry ? \App\Support\Bin::entry($entry) : null;

        $this->stamp('Torn out of the book. It’s in the bin for 30 days.', afterRedirect: true, undo: $binned?->id);
        $this->redirectRoute('admin.entries', ['collection' => $this->collection], navigate: true);
    }

    #[Computed]
    public function editions(): int
    {
        if (! $this->entryId) {
            return 0;
        }

        $site = config('booklet.default_site');

        return Booklet::revisions()->whereKey("collections/{$this->collection}/{$site}/{$this->entryId}")->count();
    }

    // Someone else has it open ------------------------------------------

    /**
     * Called every half minute while the editor is open, so others can see
     * who else is on this entry.
     */
    #[\Livewire\Attributes\Renderless]
    public function heartbeat(bool $typing = false): array
    {
        if (! $this->entryId) {
            return ['others' => [], 'newer' => null];
        }

        $key = 'booklet.editing.'.$this->entryId;
        $open = $this->openNow();
        $mine = $open[request()->user()?->id] ?? [];
        $open[request()->user()?->id] = ['at' => now()->timestamp, 'typing' => $typing ? now()->timestamp : ($mine['typing'] ?? 0)];
        Cache::put($key, $open, 120);

        // Someone else saved since this page last saved or loaded?
        $last = Cache::get('booklet.saved.'.$this->entryId);
        $newer = null;
        if ($last && (int) $last['user'] !== (int) request()->user()?->id
            && $last['at'] > (strtotime((string) $this->savedAt) ?: 0)) {
            $newer = ['name' => User::find($last['user'])?->name ?? 'Someone', 'ago' => Carbon::createFromTimestamp($last['at'])->diffForHumans()];
        }

        return ['others' => $this->coEditors(), 'newer' => $newer];
    }

    /** Everyone with this entry open in the last 45 seconds: [user id => [at, typing]]. */
    protected function openNow(): array
    {
        $raw = (array) Cache::get('booklet.editing.'.$this->entryId, []);
        $open = [];
        foreach ($raw as $id => $seen) {
            $seen = is_array($seen) ? $seen : ['at' => (int) $seen, 'typing' => 0]; // older format
            if ($seen['at'] > now()->subSeconds(45)->timestamp) {
                $open[$id] = $seen;
            }
        }

        return $open;
    }

    /**
     * Other people with this entry open right now, and whether they're typing.
     *
     * @return array<int, array{name: string, typing: bool}>
     */
    protected function coEditors(): array
    {
        if (! $this->entryId) {
            return [];
        }

        $open = $this->openNow();
        unset($open[request()->user()?->id]);
        if (! $open) {
            return [];
        }

        $names = User::whereIn('id', array_keys($open))->pluck('name', 'id');

        return collect($open)->map(fn ($seen, $id) => [
            'name' => (string) ($names[$id] ?? 'Someone'),
            'typing' => ($seen['typing'] ?? 0) > now()->subSeconds(20)->timestamp,
        ])->values()->all();
    }

    /** Take the version someone else saved, replacing what's on this page. */
    public function reloadLatest(): void
    {
        $entry = $this->entryId ? Booklet::entries()->find($this->entryId) : null;
        abort_unless($entry && $this->canEdit($entry), 404);

        $this->fill($this->valuesFrom($entry->data()));
        $this->status = $entry->status();
        $this->readTakedown($entry);
        $this->readSeries($entry);
        $this->savedAt = now()->format('Y-m-d H:i:s');
        $this->dispatch('entry-saved');
        $this->stamp('Loaded the newest version.');
    }

    // Stacy at the desk --------------------------------------------------------

    /**
     * A new version of the selected words (or what comes next, for "Keep writing").
     * Nothing changes until the person takes it, in the browser.
     *
     * @return array{text?: string, error?: string}
     */
    #[\Livewire\Attributes\Renderless]
    public function stacyWrite(string $how, string $text, string $before = ''): array
    {
        $user = request()->user();
        if (! \App\Stacy\Writer::available($user)) {
            return ['error' => 'Stacy isn’t available for you right now.'];
        }

        try {
            return ['text' => \App\Stacy\Writer::rewrite($user, $how, $text, $before, (string) ($this->values['title'] ?? ''))];
        } catch (\App\Stacy\StacyException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'Something went wrong on my side. It’s in the error log for whoever looks after the site.'];
        }
    }

    /** A translated copy of this entry, as a new draft (saved words only). */
    public function translateTo(string $lang): void
    {
        $user = request()->user();
        $entry = $this->entryId ? Booklet::entries()->find($this->entryId) : null;

        if (! $entry || ! \App\Stacy\Translations::canTranslate($user, $entry)) {
            $this->stamp('Translating isn’t available here.');

            return;
        }

        try {
            $copy = \App\Stacy\Translations::make($user, $entry, $lang);
        } catch (\App\Stacy\StacyException $e) {
            $this->stamp($e->getMessage());

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->stamp('The translation didn’t work, so nothing was made.');

            return;
        }

        $this->stamp(__(':language draft ready. Read it through before publishing.', ['language' => \App\Stacy\Writer::LANGUAGES[$lang][0]]), afterRedirect: true);
        $this->redirectRoute('admin.entries.edit', ['collection' => $copy->collectionHandle(), 'entry' => $copy->id()], navigate: true);
    }

    /** Built of sections on the page: where it stands, and where to build it (or whether it can be). */
    protected function building(): array
    {
        $pages = app(\App\PageBuilder\PageBuilder::class);
        $entry = $this->entryId ? Booklet::entries()->find($this->entryId) : null;
        if ($entry && ($build = \App\Models\PageBuild::forEntry($entry))) {
            [$status, $words] = $pages->status($build);

            return ['build' => ['status' => $status, 'words' => $words, 'url' => $pages->editUrl($entry), 'fresh' => $build->fresh], 'canBuild' => true];
        }

        return ['build' => null, 'canBuild' => $entry !== null && $pages->canBuild(request()->user(), $entry)];
    }

    /** "Build it on the page": save what's here, then off to the site to build it. */
    public function buildOnPage(): void
    {
        $this->save();
        $entry = $this->entryId ? Booklet::entries()->find($this->entryId) : null;
        if (! $entry || ! app(\App\PageBuilder\PageBuilder::class)->canBuild(request()->user(), $entry)) {
            return;
        }
        app(\App\PageBuilder\PageBuilder::class)->start($entry);
        $this->redirect(app(\App\PageBuilder\PageBuilder::class)->editUrl($entry));
    }

    public function render()
    {
        $title = $this->values['title'] ?? '';
        $this->heartbeat();

        return $this->titled(view('livewire.admin.entry-editor', [
            'canPublish' => $this->canPublish(),
            'pictureUrls' => $this->pictureUrls(),
            'pickingMany' => $this->pickingMany(),
            'hidden' => $this->hiddenFields(),
            'watched' => $this->watchedFields(),
            'canAddCutouts' => $this->canAddCutouts(),
            'statuses' => ! $this->canPublish()
                ? ['draft' => 'Draft']
                : ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published'],
            'primaryLabel' => ['draft' => 'Save draft', 'scheduled' => 'Schedule', 'published' => 'Publish'][$this->status] ?? 'Save',
            'words' => \App\Support\Milestones::words((string) ($this->values['content'] ?? '')),
            'stacyHelp' => $stacyHelp = \App\Stacy\Writer::available(request()->user()),
            'writeMenu' => \App\Stacy\Writer::HOW,
            'translations' => $this->entryId && ($current = Booklet::entries()->find($this->entryId))
                ? \App\Stacy\Translations::family($current)->reject(fn ($e) => $e->id() === $this->entryId)->values()
                : collect(),
            'translationOf' => $this->entryId ? (string) (Booklet::entries()->find($this->entryId)?->get('translation_of') ?? '') : '',
            'newsletter' => $this->model->dated() && \App\Support\Newsletter::enabled(),
            'emailed' => $this->entryId ? rescue(fn () => \App\Models\PostEmail::where('entry_id', $this->entryId)->first(), null, false) : null,
            'subscriberCount' => rescue(fn () => \App\Models\Subscriber::active()->count(), 0, false),
            'canTranslate' => $stacyHelp && $this->entryId && \App\Stacy\StacyConfig::allowsAction('translate'),
            'routePrefix' => Str::before((string) $this->model->route(), '{'),
            'searchCard' => $this->searchCard(),
            ...$this->building(),
            'coEditors' => $this->coEditors(),
            'takenDown' => $this->entryId && $this->status === 'draft'
                ? rescue(fn () => ($at = Booklet::entries()->find($this->entryId)?->get('taken_down_at')) ? Carbon::parse($at) : null, null, false) : null,
            'canTakeDown' => $this->canPublish() && $this->status !== 'draft',
            'canChooseReaders' => $this->canPublish(),
            'readerGroups' => \App\Models\Group::choices(),
            'seriesNames' => $this->model->dated() ? \App\Support\Series::all() : [],
            'termChoices' => $this->termChoices(),
            'addresses' => Links::addresses(),
            'fills' => $this->fills(),
            'people' => $this->canPublish() ? $this->people() : collect(),
            'isHome' => $this->entryId && (string) app(\App\Booklet\Site::class)->setting('homepage', 'posts') === $this->entryId,
            'review' => ($reviewed = $this->entryId ? Booklet::entries()->find($this->entryId) : null) ? \App\Support\Reviews::state($reviewed) : null,
            'reviewInfo' => $reviewed ? \App\Support\Reviews::details($reviewed) : null,
            'reviewPublished' => (bool) $reviewed?->published(),
            // How it's being read (once it's out, or if it ever was).
            'reading' => $reviewed && ($reviewed->status() === 'published' || \App\Models\ActivityLog::where('subject_key', $reviewed->id())->where('kind', 'publishing')->exists())
                ? rescue(fn () => \App\Support\VisitStats::forEntry($reviewed->id()), null, false)
                : null,
        ]), ($title !== '' ? $title : 'New entry').' · '.$this->model->title());
    }
}
