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

    public function mount(string $collection): void
    {
        abort_unless(Booklet::collections()->find($collection) !== null, 404);
        abort_unless(request()->user()->canOpen($collection), 403, 'Your role doesn’t open this chapter.');

        $this->collection = $collection;
    }

    public function pick(string $id): void
    {
        $this->selected = $id;
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
        $rows = $structured
            ? $this->treeRows($entries)
            : $entries->map(fn (Entry $e) => ['entry' => $e, 'depth' => 0, 'parent' => null])->all();

        $selected = $entries->first(fn (Entry $e) => $e->id() === $this->selected) ?? ($rows[0]['entry'] ?? null);

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
        if ($collection->dated()) {
            $statuses = ['all' => 'All', 'draft' => 'Drafts', 'scheduled' => 'Scheduled', 'published' => 'Published'];
        }
        // Drafts waiting for an editor get their own filter while there are any.
        if ($counts['review'] > 0 || $this->status === 'review') {
            $statuses = array_slice($statuses, 0, 2, true) + ['review' => 'In review'] + array_slice($statuses, 2, null, true);
        }

        return $this->titled(view('livewire.admin.entries', [
            'model' => $collection,
            'entries' => $entries,
            'rows' => $rows,
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
            'chosenReading' => $selected && $selected->status() === 'published'
                ? rescue(fn () => \App\Support\VisitStats::forEntries([$selected->id()])[$selected->id()] ?? ['views' => 0, 'reads' => 0], null, false)
                : null,
            'chosen' => $selected,
            'canEditChosen' => $selected && ($this->canArrange()
                || (string) $selected->get('author') === (string) request()->user()?->id),
            'author' => $selected?->get('author') ? User::find($selected->get('author')) : null,
            'excerpt' => $selected ? Str::limit(trim(strip_tags(Str::markdown((string) $selected->get('content', '')))), 320) : null,
            'editions' => $selected ? Booklet::revisions()->whereKey($selected->revisionKey())->count() : 0,
        ]), $collection->title());
    }
}
