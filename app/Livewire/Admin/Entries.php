<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Booklet\Data\Collection;
use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\Menus;
use App\Booklet\Support\PageTree;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A collection's chapter: the list on the left page, the chosen entry's
 * preview on the right page. Structured collections (Pages) show their
 * tree, with the template, the menu switch and nesting on the right.
 */
#[Layout('components.layouts.admin')]
class Entries extends Component
{
    use PageTitle;

    use Stamps;

    public const TEMPLATES = [
        'default' => ['Default', 'Text column'],
        'full_width' => ['Full width', 'Edge to edge'],
        'landing' => ['Landing', 'Hero + blocks'],
    ];
    public string $collection;

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'entry', except: null)]
    public ?string $selected = null;

    /** A page of a dated chapter's list: a book with thousands of posts shows them fifty at a time. */
    #[Url(except: 1)]
    public int $page = 1;

    public const PER_PAGE = 50;

    /** The table's order in the Dashboard layout: title, date or edited, "-" first for newest or Z–A. */
    #[Url(except: '')]
    public string $sort = '';

    public const SORTS = ['title', '-title', 'date', '-date', 'edited', '-edited'];

    /** On first opening with ?entry=…, turn to the page it's on. */
    protected bool $findChosen = false;

    public function mount(string $collection): void
    {
        $this->findChosen = $this->selected !== null && $this->page === 1;
        abort_unless(Booklet::collections()->find($collection) !== null, 404);
        abort_unless(request()->user()->canOpen($collection), 403, 'Your role doesn’t open this chapter.');

        $this->collection = $collection;
    }

    public function pick(string $id): void
    {
        $this->selected = $id;
    }

    public function turnTo(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    /** Sort the table by a column; again to turn the order round. */
    public function sortBy(string $column): void
    {
        abort_unless(in_array($column, ['title', 'date', 'edited'], true), 422);
        // Titles start A–Z; dates and edits start with the newest.
        $first = $column === 'title' ? 'title' : '-'.$column;
        $turned = $first === $column ? '-'.$column : $column;
        $this->sort = $this->sort === $first ? $turned : $first;
        $this->page = 1;
    }

    // Many at once ------------------------------------------------------------

    /** Entries ticked in the list. */
    public array $ticked = [];

    /** Adding a category or tag to the ticked ones: which kind, and the words. */
    public string $bulkField = '';

    public string $bulkWords = '';

    /** Tick every entry on this page (or untick them, if they all are). */
    public function tickAll(array $ids): void
    {
        $ids = array_values(array_filter($ids, 'is_string'));
        $this->ticked = array_diff($ids, $this->ticked) === []
            ? array_values(array_diff($this->ticked, $ids))
            : array_values(array_unique(array_merge($this->ticked, $ids)));
    }

    public function untickAll(): void
    {
        $this->ticked = [];
    }

    /**
     * The ticked entries this person may change: in this chapter, and for a
     * writer only their own. $publishing: only editors and admins.
     *
     * @return \Illuminate\Support\Collection<int, Entry>
     */
    protected function tickedEntries(bool $publishing = false): \Illuminate\Support\Collection
    {
        abort_if($publishing && ! $this->canArrange(), 403);
        $me = (string) request()->user()?->id;

        return collect($this->ticked)->take(500)
            ->map(fn ($id) => is_string($id) ? Booklet::entries()->find($id) : null)
            ->filter(fn (?Entry $e) => $e && $e->collectionHandle() === $this->collection
                && ($this->canArrange() || (string) $e->get('author') === $me))
            ->values();
    }

    /** History and who's waiting on a review, as the writing page does it. */
    protected function noted(Entry $entry, string $action): void
    {
        Booklet::revisions()->snapshot($entry->revisionKey(), $entry, $action, (string) request()->user()?->id);
        ActivityLog::record($action, $action === 'published' || $action === 'unpublished' ? 'publishing' : 'edits', 'entry', $entry->id(), $entry->title(), ['many' => true]);
    }

    public function publishTicked(): void
    {
        $model = Booklet::collections()->find($this->collection);
        $done = 0;
        foreach ($this->tickedEntries(publishing: true) as $entry) {
            if ($entry->status() === 'published') {
                continue;
            }
            // Out now: a draft without a day, or one set for later, goes out today.
            if ($model?->dated() && (! $entry->date() || $entry->date()->isFuture())) {
                $entry->setAttribute('date', now());
            }
            // A page waiting for its time goes out now, and keeps no date.
            if (! $model?->dated()) {
                $entry->setAttribute('date', null);
            }
            $entry->setAttribute('published', true)->remove('taken_down_at')->save();
            app(\App\Inserts\InsertManager::class)->fire('entry.published', $entry);
            \App\Support\Reviews::settle($entry, request()->user());
            $this->noted($entry, 'published');
            $done++;
        }
        $this->finishMany($done, __(':count published.'), __('Those were already out.'));
    }

    public function draftTicked(): void
    {
        $done = 0;
        foreach ($this->tickedEntries(publishing: true) as $entry) {
            if ($entry->status() === 'draft') {
                continue;
            }
            $entry->setAttribute('published', false)->save();
            $this->noted($entry, 'unpublished');
            $done++;
        }
        $this->finishMany($done, __(':count made drafts. Readers can’t see them now.'), __('Those were drafts already.'));
    }

    public function termTicked(): void
    {
        $field = $this->termFields()[$this->bulkField] ?? null;
        $title = trim(preg_replace('/\s+/', ' ', $this->bulkWords) ?? '');
        if (! $field || $title === '' || Str::slug($title) === '') {
            $this->addError('bulkWords', __('Choose or type one to add.'));

            return;
        }
        $slug = Str::slug($title);
        if (! Booklet::terms()->findBySlug($field['taxonomy'], $slug)) {
            Booklet::terms()->make(['taxonomy' => $field['taxonomy'], 'slug' => $slug], ['title' => mb_substr($title, 0, 80)])->save();
        }

        $done = 0;
        foreach ($this->tickedEntries() as $entry) {
            $now = array_values(array_filter((array) $entry->get($field['handle'], []), 'is_string'));
            if (in_array($slug, $now, true)) {
                continue;
            }
            // Only room for one (a post's category): this one takes its place.
            $new = $field['max'] === 1 ? [$slug] : array_merge($now, [$slug]);
            if ($field['max'] > 1 && count($new) > $field['max']) {
                continue;
            }
            $entry->set($field['handle'], $new)->save();
            $this->noted($entry, 'edited');
            $done++;
        }
        $this->bulkWords = '';
        $this->finishMany($done, $field['max'] === 1 ? __(':count now in “:name”.', ['name' => $title, 'count' => ':count']) : __('“:name” added to :count.', ['name' => $title, 'count' => ':count']), __('They all had it already.'));
    }

    public function binTicked(): void
    {
        $binned = [];
        foreach ($this->tickedEntries() as $entry) {
            $binned[] = \App\Support\Bin::entry($entry, request()->user());
        }
        if ($this->selected && in_array($this->selected, $this->ticked, true)) {
            $this->selected = null;
        }
        $this->ticked = [];
        if ($binned === []) {
            return;
        }
        // One can be put straight back; more wait in the bin.
        $this->stamp(count($binned) === 1
            ? 'Moved to the bin.'
            : trans_choice(':count moved to the bin. They wait there for 30 days.|:count moved to the bin. They wait there for 30 days.', count($binned)),
            undo: count($binned) === 1 ? $binned[0]->id : null);
    }

    /** The bin, from one row's own menu. */
    public function binOne(string $id): void
    {
        $this->ticked = [$id];
        $this->binTicked();
    }

    protected function finishMany(int $done, string $said, string $nothing): void
    {
        $this->ticked = [];
        $this->stamp($done > 0 ? str_replace(':count', (string) $done, $said) : $nothing);
    }

    /**
     * The chapter's category and tag boxes: handle => taxonomy, label, how many fit.
     *
     * @return array<string, array{handle: string, taxonomy: string, label: string, max: int}>
     */
    protected function termFields(): array
    {
        $model = Booklet::collections()->find($this->collection);
        $blueprint = $model?->blueprints()->first();
        $out = [];
        foreach ((array) $blueprint?->tabs() as $tab) {
            foreach ((array) ($tab['sections'] ?? []) as $section) {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    $config = (array) ($field['field'] ?? []);
                    $taxonomy = ((array) ($config['taxonomies'] ?? []))[0] ?? null;
                    if (($config['type'] ?? '') === 'terms' && is_string($taxonomy) && isset($field['handle'])) {
                        $out[(string) $field['handle']] = [
                            'handle' => (string) $field['handle'],
                            'taxonomy' => $taxonomy,
                            'label' => (string) ($config['display'] ?? Str::headline((string) $field['handle'])),
                            'max' => (int) ($config['max_items'] ?? 0),
                        ];
                    }
                }
            }
        }

        return $out;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    /** Edit and publish anyone's entries in this chapter (editors, admins, and cards that publish here). */
    protected function canArrange(): bool
    {
        return request()->user()->canPublishIn($this->collection);
    }

    protected function chosenEntry(): Entry
    {
        $entry = $this->selected ? Booklet::entries()->find($this->selected) : null;
        abort_unless($entry && $entry->collectionHandle() === $this->collection, 404);

        return $entry;
    }

    public function setTemplate(string $template): void
    {
        abort_unless(array_key_exists($template, self::TEMPLATES), 422);
        $entry = $this->chosenEntry();
        abort_unless($this->canArrange() || (string) $entry->get('author') === (string) request()->user()?->id, 403);

        $entry->set('template', $template)->save();
        Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'edited', (string) request()->user()?->id, 'Template: '.self::TEMPLATES[$template][0]);
        $this->stamp('Template changed.');
    }

    public function toggleMenu(): void
    {
        abort_unless($this->canArrange(), 403);
        $entry = $this->chosenEntry();

        if (Menus::has('header', $entry->id())) {
            Menus::remove('header', $entry->id());
            $this->stamp('Taken out of the site menu.');
        } else {
            Menus::add('header', $entry->id());
            $this->stamp('Added to the site menu.');
        }

        ActivityLog::record('changed the site menu for', 'settings', 'entry', $entry->id(), $entry->title());
    }

    public function moveUnder(string $parent): void
    {
        abort_unless($this->canArrange(), 403);
        $entry = $this->chosenEntry();

        PageTree::for($this->collection)->place($entry->id(), $parent !== '' ? $parent : null)->save();
        ActivityLog::record('moved', 'edits', 'entry', $entry->id(), $entry->title());
        $this->stamp('Moved. Its address follows along.');
    }

    public function nudge(int $by): void
    {
        abort_unless($this->canArrange(), 403);
        $entry = $this->chosenEntry();

        $tree = PageTree::for($this->collection);
        if (! $tree->contains($entry->id())) {
            $tree->place($entry->id());
        }
        $tree->nudge($entry->id(), $by < 0 ? -1 : 1)->save();
    }

    // The page tree as a table (Dashboard layout): the same actions, one row at a time.

    /** The page being moved under another, while its picker is open. */
    public ?string $moving = null;

    public function templateFor(string $id, string $template): void
    {
        $this->selected = $id;
        $this->setTemplate($template);
    }

    public function menuFor(string $id): void
    {
        $this->selected = $id;
        $this->toggleMenu();
    }

    public function nudgeRow(string $id, int $by): void
    {
        $this->selected = $id;
        $this->nudge($by);
    }

    public function startMove(string $id): void
    {
        abort_unless($this->canArrange(), 403);
        $this->selected = $id;
        $this->chosenEntry();
        $this->moving = $id;
    }

    public function moveTo(string $parent): void
    {
        abort_unless($this->moving !== null, 422);
        $this->selected = $this->moving;
        $this->moving = null;
        $this->moveUnder($parent);
    }

    public function cancelMove(): void
    {
        $this->moving = null;
    }

    /**
     * Pages in tree order, with their depth; any page not in the tree yet comes last.
     */
    protected function treeRows($entries): array
    {
        $byId = $entries->keyBy(fn (Entry $e) => $e->id());
        $rows = [];

        foreach (PageTree::for($this->collection)->flatten() as $row) {
            if ($entry = $byId->pull($row['id'])) {
                $rows[] = ['entry' => $entry, 'depth' => $row['depth'], 'parent' => $row['parent']];
            }
        }

        foreach ($byId as $entry) {
            $rows[] = ['entry' => $entry, 'depth' => 0, 'parent' => null];
        }

        return $rows;
    }

    public function render()
    {
        /** @var Collection $collection */
        $collection = Booklet::collections()->find($this->collection);
        $all = Booklet::entries()->whereCollection($this->collection);
        $termFields = $this->termFields();
        if (! isset($termFields[$this->bulkField])) {
            $this->bulkField = (string) array_key_first($termFields);
        }
        $this->ticked = array_values(array_intersect($this->ticked, $all->map(fn (Entry $e) => $e->id())->all()));

        $counts = $all->countBy(fn (Entry $e) => $e->status());
        $reviewStates = $all->mapWithKeys(fn (Entry $e) => [$e->id() => \App\Support\Reviews::state($e)])->filter();
        $counts->put('review', $reviewStates->filter(fn ($s) => $s === 'waiting')->count());

        $entries = $all
            ->when($this->status === 'review', fn ($c) => $c->filter(fn (Entry $e) => ($reviewStates[$e->id()] ?? null) === 'waiting'))
            ->when(! in_array($this->status, ['all', 'review'], true), fn ($c) => $c->filter(fn (Entry $e) => $e->status() === $this->status))
            ->when($this->search !== '', fn ($c) => $c->filter(
                fn (Entry $e) => Str::contains(Str::lower($e->title().' '.$e->slug()), Str::lower($this->search))
            ))
            ->values();

        $structured = $collection->structured();
        // The Dashboard layout shows the chapter as a table, which can be sorted.
        $desk = \App\Support\YourBook::of(request()->user())['layout'] === 'dashboard';
        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = '';
        }
        if ($this->sort !== '' && ! $structured) {
            $by = ltrim($this->sort, '-');
            $entries = $entries->sortBy(fn (Entry $e) => match ($by) {
                'title' => Str::lower((string) $e->title()),
                'date' => (string) ($e->date()?->getTimestamp() ?? 0),
                default => (string) ($e->lastModified() ?? ''),
            }, SORT_NATURAL, str_starts_with($this->sort, '-'))->values();
        }
        // The page tree is shown whole (it's arranged by dragging); dated chapters a page at a time.
        $lastPage = $structured ? 1 : max(1, (int) ceil($entries->count() / self::PER_PAGE));
        if ($this->findChosen && ! $structured && ($at = $entries->search(fn (Entry $e) => $e->id() === $this->selected)) !== false) {
            $this->page = intdiv((int) $at, self::PER_PAGE) + 1;
        }
        $this->page = min(max(1, $this->page), $lastPage);
        $rows = $structured
            ? $this->treeRows($entries)
            : $entries->forPage($this->page, self::PER_PAGE)->map(fn (Entry $e) => ['entry' => $e, 'depth' => 0, 'parent' => null])->values()->all();

        $selected = $entries->first(fn (Entry $e) => $e->id() === $this->selected && collect($rows)->contains(fn ($row) => $row['entry']->id() === $e->id()))
            ?? ($rows[0]['entry'] ?? null);

        // The entry shown on the right is the one the buttons act on. Without this, the
        // first entry was shown by default but nothing was "chosen", so setting its
        // template, menu, parent or order answered 404.
        $this->selected = $selected?->id();
        $selectedRow = collect($rows)->first(fn ($row) => $row['entry']->id() === $selected?->id());

        $cover = null;
        if ($selected && ($path = ((array) $selected->get('cover', []))[0] ?? null)) {
            $disk = Booklet::assetContainers()->find('media')?->attribute('disk') ?? 'public';
            try {
                $cover = \App\Support\Disk::of($disk)->url($path);
            } catch (\Throwable) {
                $cover = null;
            }
        }

        $tags = $selected
            ? collect((array) $selected->get('tags', []))->merge((array) $selected->get('categories', []))->unique()->values()->all()
            : [];

        $statuses = ['all' => 'All', 'draft' => 'Drafts', 'published' => 'Published'];
        // Pages get the Scheduled tab while any are waiting to go out.
        if ($collection->dated() || ($counts['scheduled'] ?? 0) > 0 || $this->status === 'scheduled') {
            $statuses = ['all' => 'All', 'draft' => 'Drafts', 'scheduled' => 'Scheduled', 'published' => 'Published'];
        }
        // Drafts waiting for an editor get their own filter while there are any.
        if ($counts['review'] > 0 || $this->status === 'review') {
            $statuses = array_slice($statuses, 0, 2, true) + ['review' => 'In review'] + array_slice($statuses, 2, null, true);
        }

        $tree = $structured ? PageTree::for($this->collection) : null;
        $movingEntry = $structured && $this->moving ? $all->first(fn (Entry $e) => $e->id() === $this->moving) : null;
        $authors = $desk
            ? User::query()->whereIn('id', collect($rows)->map(fn ($row) => $row['entry']->get('author'))->filter()->unique()->values())->get()->keyBy('id')
            : collect();

        return $this->titled(view($desk ? 'livewire.admin.entries-table' : 'livewire.admin.entries', [
            'authors' => $authors,
            'menuIds' => $desk && $structured ? collect($rows)->map(fn ($row) => $row['entry']->id())->filter(fn ($id) => Menus::has('header', $id))->values()->all() : [],
            'movingEntry' => $movingEntry,
            'moveChoices' => $movingEntry
                ? $all->reject(fn (Entry $e) => $e->id() === $movingEntry->id()
                    || in_array($e->id(), $tree->descendantsOf($movingEntry->id()), true))->values()
                : collect(),
            'sort' => $this->sort,
            'model' => $collection,
            'entries' => $entries,
            'rows' => $rows,
            'pageIds' => array_map(fn ($row) => $row['entry']->id(), $rows),
            'termFields' => $termFields,
            'termTitles' => $termFields === [] ? [] : Booklet::terms()->all()
                ->filter(fn ($t) => $t->attribute('taxonomy') === ($termFields[$this->bulkField]['taxonomy'] ?? null))
                ->map(fn ($t) => $t->title())->sort()->values()->all(),
            'lastPage' => $lastPage,
            'shown' => $entries->count(),
            'structured' => $structured,
            'canArrange' => $this->canArrange(),
            'templates' => self::TEMPLATES,
            'inMenu' => $selected && $structured ? Menus::has('header', $selected->id()) : false,
            'parentEntry' => $selectedRow && $selectedRow['parent'] ? Booklet::entries()->find($selectedRow['parent']) : null,
            'parentChoices' => $selected && $structured
                ? $all->reject(fn (Entry $e) => $e->id() === $selected->id()
                    || in_array($e->id(), PageTree::for($this->collection)->descendantsOf($selected->id()), true))->values()
                : collect(),
            'cover' => $cover,
            'noteCounts' => \App\Models\EntryNote::openCounts(),
            'tags' => $tags,
            'hasTaxonomies' => (bool) $collection->get('taxonomies'),
            'total' => $all->count(),
            'counts' => $counts,
            'statuses' => $statuses,
            'reviewStates' => $reviewStates,
            'chosenReading' => ! $desk && $selected && $selected->status() === 'published'
                ? rescue(fn () => \App\Support\VisitStats::forEntries([$selected->id()])[$selected->id()] ?? ['views' => 0, 'reads' => 0], null, false)
                : null,
            'chosen' => $selected,
            'canEditChosen' => $selected && ($this->canArrange()
                || (string) $selected->get('author') === (string) request()->user()?->id),
            'author' => $selected?->get('author') ? User::find($selected->get('author')) : null,
            'excerpt' => $selected ? Str::limit(trim(strip_tags(Str::markdown((string) $selected->get('content', '')))), 320) : null,
            'editions' => ! $desk && $selected ? Booklet::revisions()->whereKey($selected->revisionKey())->count() : 0,
        ]), $collection->title());
    }
}
