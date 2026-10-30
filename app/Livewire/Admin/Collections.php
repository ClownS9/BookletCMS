<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Blueprint;
use App\Booklet\Data\Collection as CollectionModel;
use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use App\Livewire\Concerns\Stamps;
use Livewire\Component;

/**
 * Collections: bind new chapters and arrange their blueprint, the same
 * idea as Statamic's collections and blueprints, edited in one place.
 */
#[Layout('components.layouts.admin')]
#[Title('Collections')]
class Collections extends Component
{
    use Stamps;

    /** Field types the editor knows how to draw. */
    public const TYPES = [
        'text' => 'Text',
        'markdown' => 'Rich text',
        'textarea' => 'Long text',
        'integer' => 'Number',
        'date' => 'Date',
        'assets' => 'Image',
        'toggle' => 'Toggle',
        'select' => 'Select',
        'terms' => 'Tags',
        'link' => 'Link',
        'blocks' => 'Blocks',
        'entries' => 'Linked entries',
        'grid' => 'Rows',
    ];

    public const STICKERS = ['#F3C24F', '#86CDB9', '#F4876A', '#93B4F2', '#C5AAF0', '#F2A7C3', '#E3D7BE'];

    #[Url(as: 'chapter', except: null)]
    public ?string $selected = null;

    /** The handle being edited, or null for a new collection. */
    public ?string $editing = null;

    public string $title = '';

    public string $handle = '';

    public string $route = '';

    public bool $dated = false;

    public string $sticker = '#F3C24F';

    public array $taxonomies = [];

    /**
     * Blueprint fields as editable rows:
     * ['key', 'handle', 'display', 'type', 'required', 'tab', 'options', 'taxonomy', 'import']
     */
    public array $fields = [];

    public bool $handleTouched = false;

    public bool $saved = false;

    public function mount(): void
    {
        $this->selected ??= Booklet::collections()->all()->first()?->handle();
        $this->selected ? $this->load($this->selected) : $this->startNew();
    }

    protected function canEdit(): bool
    {
        return (bool) request()->user()?->isAdmin();
    }

    public function pick(string $handle): void
    {
        $this->selected = $handle;
        $this->load($handle);
    }

    public function newChapter(): void
    {
        abort_unless($this->canEdit(), 403);

        $this->startNew();
    }

    protected function startNew(): void
    {
        $this->resetValidation();
        $this->selected = null;
        $this->editing = null;
        $this->title = '';
        $this->handle = '';
        $this->route = '';
        $this->dated = false;
        $this->handleTouched = false;
        $this->saved = false;
        $this->taxonomies = [];
        $this->sticker = self::STICKERS[Booklet::collections()->count() % count(self::STICKERS)];
        $this->fields = [
            $this->row(['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'validate' => ['required']]], 'main'),
            $this->row(['handle' => 'content', 'field' => ['type' => 'markdown', 'display' => 'Body']], 'main'),
        ];
    }

    protected function load(string $handle): void
    {
        $collection = Booklet::collections()->find($handle);

        if (! $collection) {
            $this->startNew();

            return;
        }

        $this->resetValidation();
        $this->editing = $handle;
        $this->title = $collection->title();
        $this->handle = $handle;
        $this->route = (string) $collection->route();
        $this->dated = $collection->dated();
        $this->sticker = (string) $collection->get('sticker', self::STICKERS[0]);
        $this->taxonomies = $collection->taxonomies();
        $this->handleTouched = true;
        $this->saved = false;

        $this->fields = [];
        $blueprint = $this->blueprintFor($handle);

        foreach ($blueprint?->tabs() ?? [] as $tabHandle => $tab) {
            foreach ((array) ($tab['sections'] ?? []) as $section) {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    $this->fields[] = $this->row($field, $tabHandle === 'main' ? 'main' : 'sidebar');
                }
            }
        }
    }

    protected function blueprintFor(string $handle): ?Blueprint
    {
        return Booklet::blueprints()->findIn("collections.{$handle}", Str::singular($handle))
            ?? Booklet::blueprints()->inNamespace("collections.{$handle}")->first();
    }

    protected function row(array $field, string $tab): array
    {
        $config = (array) ($field['field'] ?? []);
        $options = (array) ($config['options'] ?? []);

        return [
            'key' => Str::random(8),
            'import' => $field['import'] ?? null,
            'handle' => (string) ($field['handle'] ?? ''),
            'display' => (string) ($config['display'] ?? Str::headline((string) ($field['handle'] ?? ''))),
            'type' => (string) ($config['type'] ?? 'text'),
            'required' => in_array('required', (array) ($config['validate'] ?? []), true),
            'tab' => $tab,
            'options' => implode(', ', array_is_list($options) ? $options : array_values($options)),
            'taxonomy' => ((array) ($config['taxonomies'] ?? []))[0] ?? 'tags',
            'from' => (string) (((array) ($config['collections'] ?? []))[0] ?? ''),
            'max' => isset($config['max_items']) && ($config['type'] ?? null) === 'entries' ? (string) $config['max_items'] : '',
            'columns' => ($config['type'] ?? null) === 'grid' ? self::columnsText($config) : '',
            // Show-this-field-when: the simple form the builder edits (or "complex", kept as written).
            'when' => is_array($simple = \App\Support\Conditions::simple($config)) ? $simple : ['mode' => 'if', 'field' => '', 'op' => '', 'value' => ''],
            'when_complex' => $simple === false,
            'when_open' => false,
            'when_words' => '',
            'extra' => array_diff_key($config, array_flip(['type', 'display', 'validate', 'options', 'taxonomies'])),
        ];
    }

    // Editing --------------------------------------------------------------

    public function updatedTitle(): void
    {
        if (! $this->handleTouched && ! $this->editing) {
            $this->handle = Str::snake(Str::slug($this->title, ' '));
            $this->route = '/'.Str::slug($this->title).'/{slug}';
        }
    }

    public function updatedHandle(): void
    {
        $this->handleTouched = true;
        $this->handle = Str::snake(Str::slug($this->handle, ' '));
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function addField(string $type): void
    {
        abort_unless(array_key_exists($type, self::TYPES), 422);

        $base = Str::snake(self::TYPES[$type]);
        $handle = $base;
        $n = 2;
        while (collect($this->fields)->contains('handle', $handle)) {
            $handle = $base.'_'.$n++;
        }

        $this->fields[] = $this->row([
            'handle' => $handle,
            'field' => ['type' => $type, 'display' => self::TYPES[$type].($n > 2 ? ' '.($n - 1) : '')],
        ], in_array($type, ['text', 'markdown', 'textarea', 'blocks', 'grid'], true) ? 'main' : 'sidebar');

        $this->saved = false;
    }

    public function move(string $key, int $direction): void
    {
        $index = collect($this->fields)->search(fn ($f) => $f['key'] === $key);
        $target = $index + $direction;

        if ($index === false || ! isset($this->fields[$target])) {
            return;
        }

        [$this->fields[$index], $this->fields[$target]] = [$this->fields[$target], $this->fields[$index]];
        $this->saved = false;
    }

    public function removeField(string $key): void
    {
        $this->fields = array_values(array_filter($this->fields, fn ($f) => $f['key'] !== $key));
        $this->saved = false;
    }

    public function toggleTaxonomy(string $handle): void
    {
        $this->taxonomies = in_array($handle, $this->taxonomies, true)
            ? array_values(array_diff($this->taxonomies, [$handle]))
            : [...$this->taxonomies, $handle];
        $this->saved = false;
    }

    // Saving ---------------------------------------------------------------

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $this->validate([
            'title' => ['required', 'string', 'max:80'],
            'handle' => array_filter([
                'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                $this->editing ? null : function ($attribute, $value, $fail) {
                    if (Booklet::collections()->find($value)) {
                        $fail('There’s already a collection with that handle.');
                    }
                },
            ]),
            'route' => ['nullable', 'string', 'max:120'],
            'sticker' => ['required', Rule::in(self::STICKERS)],
            'fields' => ['array', 'min:1'],
            'fields.*.handle' => ['required_without:fields.*.import', 'nullable', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.display' => ['required_without:fields.*.import', 'nullable', 'string', 'max:80'],
            'fields.*.type' => [Rule::in(array_keys(self::TYPES))],
            'fields.*.columns' => ['nullable', 'string', 'max:600'],
            'fields.*.max' => ['nullable', 'integer', 'min:1', 'max:'.\App\Support\Links::MAX],
        ], [
            'handle.regex' => 'Use lowercase letters, numbers and underscores, starting with a letter.',
            'fields.*.handle.regex' => 'Use lowercase letters, numbers and underscores.',
            'fields.*.handle.distinct' => 'Two fields can’t share a handle.',
        ], [
            'fields.*.handle' => 'field handle',
            'fields.*.display' => 'field name',
        ]);

        $isNew = $this->editing === null;
        $handle = $isNew ? $this->handle : $this->editing;

        /** @var CollectionModel $collection */
        $collection = Booklet::collections()->find($handle)
            ?? Booklet::collections()->make(['handle' => $handle]);

        $collection->setAttribute('title', $this->title)->merge([
            'route' => $this->route !== '' ? $this->route : null,
            'dated' => $this->dated,
            'sticker' => $this->sticker,
        ])->set('taxonomies', $this->taxonomies);

        if ($this->route === '') {
            $collection->remove('route');
        }

        Booklet::collections()->save($collection);

        $blueprint = $this->blueprintFor($handle)
            ?? Booklet::blueprints()->make(['namespace' => "collections.{$handle}", 'handle' => Str::singular($handle)]);

        $blueprint->set('title', Str::singular($this->title))->set('tabs', $this->tabsFromFields());
        Booklet::blueprints()->save($blueprint);

        ActivityLog::record($isNew ? 'bound a new chapter,' : 'changed the blueprint of', 'settings', 'collection', $handle, $this->title);

        $this->editing = $handle;
        $this->selected = $handle;
        $this->saved = true;
        $this->stamp($isNew ? "{$this->title} is bound into the book." : 'Blueprint saved.');
    }

    // Show-this-field-when ---------------------------------------------------------------------

    /**
     * The other fields a rule can look at: [handle => display, type, options].
     *
     * @return array<string, array{display: string, type: string, options: array}>
     */
    public function whenFields(string $except): array
    {
        $out = [];
        foreach ($this->fields as $row) {
            if ($row['import'] || $row['key'] === $except || ($row['handle'] ?? '') === '') {
                continue;
            }
            $choices = array_values(array_filter(array_map('trim', explode(',', (string) $row['options']))));
            $out[$row['handle']] = [
                'display' => (string) ($row['display'] ?: Str::headline($row['handle'])),
                'type' => (string) $row['type'],
                'options' => $row['type'] === 'select' ? (array_combine(array_map(fn ($o) => Str::snake(Str::slug($o, ' ')), $choices), $choices) ?: []) : [],
            ];
        }

        return $out;
    }

    /** "Only when Has button is on" → the rule, read by Booklet, or by Stacy when it's less plain. */
    public function whenFromWords(int $i): void
    {
        abort_unless($this->canEdit() && isset($this->fields[$i]), 403);
        $this->resetErrorBag("fields.$i.when_words");
        $words = mb_substr(trim((string) ($this->fields[$i]['when_words'] ?? '')), 0, 300);
        if ($words === '') {
            return;
        }
        $others = $this->whenFields($this->fields[$i]['key']);
        $rule = \App\Support\Conditions::fromWords($words, $others);

        if ($rule === null && \App\Stacy\Writer::available(request()->user())) {
            try {
                $rule = \App\Stacy\Writer::condition(request()->user(), $words, (string) $this->fields[$i]['display'], $others);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if ($rule === null) {
            $this->addError("fields.$i.when_words", 'I couldn’t tell which field that’s about. Name one of: '.collect($others)->pluck('display')->implode(', ').'.');

            return;
        }

        $this->fields[$i]['when'] = $rule;
        $this->fields[$i]['when_complex'] = false;
        $this->fields[$i]['when_words'] = '';
        $this->saved = false;
    }

    public function clearWhen(int $i): void
    {
        abort_unless(isset($this->fields[$i]), 404);
        $this->fields[$i]['when'] = ['mode' => 'if', 'field' => '', 'op' => '', 'value' => ''];
        $this->fields[$i]['when_complex'] = false;
        $this->fields[$i]['when_open'] = false;
        $this->saved = false;
    }

    /** A rows field's columns as typed in the builder: "Amount, Item, Photo (picture)". */
    public static function columnsText(array $config): string
    {
        return collect(\App\Support\Rows::columns($config))
            ->map(fn ($c) => $c['display'].($c['type'] === 'text' ? '' : ' ('.$c['type'].')'))
            ->implode(', ');
    }

    /**
     * "Amount, Item, Photo (picture)" into Statamic grid columns. A column
     * that keeps its name keeps its handle (so rows already written keep
     * their values); one without a kind given keeps the kind it had.
     */
    public static function columnsFromText(string $text, array $existing): array
    {
        $before = [];
        foreach ($existing as $column) {
            if (isset($column['handle'])) {
                $before[$column['handle']] = (string) (($column['field'] ?? [])['type'] ?? 'text');
            }
        }

        $columns = [];
        foreach (array_filter(array_map('trim', explode(',', $text))) as $part) {
            $type = null;
            if (preg_match('/^(.*?)\s*\(\s*([a-z ]+?)\s*\)$/i', $part, $m)) {
                [$part, $type] = [trim($m[1]), strtolower(trim($m[2]))];
                $type = ['words' => 'text', 'long words' => 'textarea', 'yes or no' => 'toggle', 'image' => 'picture', 'photo' => 'picture', 'url' => 'link'][$type] ?? $type;
                $type = array_key_exists($type, \App\Support\Rows::COLUMN_TYPES) ? $type : null;
            }
            $handle = Str::snake(Str::slug($part, ' ')) ?: 'column';
            if ($handle === 'id' || isset($columns[$handle]) || ! preg_match('/^[a-z]/', $handle)) {
                $handle = 'col_'.$handle;
            }
            if ($part === '' || isset($columns[$handle])) {
                continue;
            }
            $columns[$handle] = ['handle' => $handle, 'field' => ['type' => $type ?? ($before[$handle] ?? 'text'), 'display' => $part]];
        }

        return array_values($columns);
    }

    protected function tabsFromFields(): array
    {
        $tabs = [
            'main' => ['display' => 'Main', 'sections' => [['fields' => []]]],
            'sidebar' => ['display' => 'Margin notes', 'sections' => [['fields' => []]]],
        ];

        foreach ($this->fields as $row) {
            if ($row['import']) {
                $tabs[$row['tab']]['sections'][0]['fields'][] = ['import' => $row['import']];

                continue;
            }

            $config = array_merge((array) ($row['extra'] ?? []), [
                'type' => $row['type'],
                'display' => $row['display'],
            ]);

            // Show-this-field-when, unless it's a richer rule the builder only keeps.
            if (! ($row['when_complex'] ?? false)) {
                $config = array_diff_key($config, \App\Support\Conditions::KEYS);
                $when = (array) ($row['when'] ?? []);
                $others = collect($this->fields)->reject(fn ($r) => $r['import'] || $r['key'] === $row['key'])->pluck('handle')->all();
                if (in_array($when['field'] ?? '', $others, true)) {
                    $config += \App\Support\Conditions::fromSimple($when);
                }
            }

            if ($row['required']) {
                $config['validate'] = ['required'];
            }

            if ($row['type'] === 'select') {
                $options = array_values(array_filter(array_map('trim', explode(',', (string) $row['options']))));
                $config['options'] = array_combine(array_map(fn ($o) => Str::snake(Str::slug($o, ' ')), $options), $options) ?: [];
            }

            if ($row['type'] === 'terms') {
                $config['taxonomies'] = [$row['taxonomy'] ?: 'tags'];
            }

            if ($row['type'] === 'entries') {
                unset($config['collections'], $config['max_items']);
                if (($row['from'] ?? '') !== '' && Booklet::collections()->find($row['from'])) {
                    $config['collections'] = [$row['from']];
                }
                if ((int) ($row['max'] ?? 0) > 0) {
                    $config['max_items'] = min(\App\Support\Links::MAX, (int) $row['max']);
                }
            }

            if ($row['type'] === 'grid') {
                $config['fields'] = self::columnsFromText((string) ($row['columns'] ?? ''), (array) ($row['extra']['fields'] ?? []));
            }

            $tabs[$row['tab']]['sections'][0]['fields'][] = ['handle' => $row['handle'], 'field' => $config];
        }

        return array_filter($tabs, fn ($tab) => $tab['sections'][0]['fields'] !== []);
    }

    public function delete(): void
    {
        $this->resetErrorBag(); // clear the last try's message

        abort_unless($this->canEdit() && $this->editing, 403);

        $count = Booklet::entries()->whereCollection($this->editing)->count();

        if ($count > 0) {
            $this->addError('delete', trans_choice(':title still has :count entry. Move or delete it first.|:title still has :count entries. Move or delete them first.', $count, ['title' => $this->title]));

            return;
        }

        foreach (Booklet::blueprints()->inNamespace("collections.{$this->editing}") as $blueprint) {
            Booklet::blueprints()->delete($blueprint);
        }
        Booklet::collections()->delete($this->editing);
        ActivityLog::record('removed the chapter', 'settings', 'collection', $this->editing, $this->title);

        $this->selected = Booklet::collections()->all()->first()?->handle();
        $this->selected ? $this->load($this->selected) : $this->startNew();
        $this->stamp('Chapter removed.');
    }

    public function render()
    {
        $collections = Booklet::collections()->all();
        $counts = Booklet::entries()->all()->countBy(fn (Entry $e) => $e->collectionHandle());

        return view('livewire.admin.collections', [
            'canEdit' => $this->canEdit(),
            'collections' => $collections,
            'counts' => $counts,
            'types' => self::TYPES,
            'stickers' => self::STICKERS,
            'allTaxonomies' => Booklet::taxonomies()->all(),
            'fieldsets' => Booklet::fieldsets()->all()->mapWithKeys(fn ($f) => [$f->handle() => $f->title()]),
        ]);
    }
}
