<?php

namespace App\Support;

use App\Booklet\Data\Asset;
use App\Booklet\Data\Entry;
use App\Booklet\Data\Term;
use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * What the search window finds: entries in every collection, files, people,
 * tags and categories, chapters of the book, and things you can do.
 *
 * Understands two little helpers in the query:
 *   status:draft | status:published | status:scheduled
 *   in:posts | in:pages | in:media | in:people | in:tags | in:go
 */
class SearchIndex
{
    public const PER_GROUP = 6;

    public const STATUSES = ['draft', 'published', 'scheduled'];

    public function __construct(
        public string $query = '',
        public string $scope = 'all',
    ) {
        // Pull the helpers out of the words.
        $this->query = trim(preg_replace_callback('/\b(status|in):([a-z0-9_-]+)/i', function ($m) {
            if (strtolower($m[1]) === 'in') {
                $this->scope = strtolower($m[2]);
            } else {
                $this->status = strtolower($m[2]);
            }

            return '';
        }, $query));
    }

    public ?string $status = null;

    public function needle(): string
    {
        return Str::lower($this->query);
    }

    protected function matches(string $haystack): bool
    {
        return $this->needle() === '' || Str::contains(Str::lower($haystack), $this->needle());
    }

    /**
     * The filters along the top: All, each collection, Media, People, Tags, Go to.
     *
     * @return array<string, array{label: string, tone: string}>
     */
    public static function scopes(): array
    {
        $scopes = ['all' => ['label' => 'All', 'tone' => 'var(--ink)']];

        foreach (Booklet::collections()->all()->sortBy(fn ($c) => [$c->handle() === 'posts' ? 0 : ($c->handle() === 'pages' ? 1 : 2), $c->title()]) as $c) {
            if (request()->user()?->canOpen($c->handle()) === false) {
                continue;
            }
            $scopes[$c->handle()] = ['label' => $c->title(), 'tone' => $c->get('sticker', 'var(--sand)')];
        }

        return $scopes + [
            'media' => ['label' => 'Media', 'tone' => 'var(--sky)'],
            'people' => ['label' => 'People', 'tone' => 'var(--lilac)'],
            'tags' => ['label' => 'Tags', 'tone' => 'var(--coral)'],
            'go' => ['label' => 'Go to', 'tone' => 'var(--sand)'],
        ];
    }

    /**
     * Results in groups, plus how many each filter would show.
     *
     * @return array{groups: array<int, array{key: string, name: string, items: array}>, counts: array<string, int>}
     */
    public function run(): array
    {
        $collections = Booklet::collections()->all()->keyBy(fn ($c) => $c->handle());
        $searching = $this->needle() !== '' || $this->status !== null;
        $groups = [];
        $counts = [];

        // Entries, by collection
        $entries = Booklet::entries()->all()
            ->filter(fn (Entry $e) => (request()->user()?->canOpen($e->collectionHandle()) ?? true) && $this->matches($e->title().' '.$e->slug().' '.$e->uri().' '.$e->get('excerpt')))
            ->when($this->status, fn ($c) => $c->filter(fn (Entry $e) => $e->status() === $this->status))
            ->sortByDesc(fn (Entry $e) => $e->lastModified())
            ->groupBy(fn (Entry $e) => $e->collectionHandle());

        foreach ($collections as $handle => $collection) {
            $items = $entries->get($handle, collect());
            $counts[$handle] = $searching ? $items->count() : 0;
            if (! $searching || $items->isEmpty() || ! $this->wants($handle)) {
                continue;
            }

            $groups[] = [
                'key' => $handle,
                'name' => $collection->title(),
                'items' => $items->take($this->limit())->map(fn (Entry $e) => $this->entryItem($e, $handle))->values()->all(),
            ];
        }

        $wordsOnly = $this->status === null;

        // Media
        $assets = $wordsOnly && $searching
            ? Booklet::assets()->all()->filter(fn (Asset $a) => $this->matches($a->path().' '.$a->get('alt')))
            : collect();
        $counts['media'] = $assets->count();
        if ($assets->isNotEmpty() && $this->wants('media')) {
            $groups[] = ['key' => 'media', 'name' => 'Media', 'items' => $assets->take($this->limit())->map(fn (Asset $a) => [
                'label' => (string) $a->attribute('basename'),
                'meta' => trim($a->container().' · '.$a->attribute('folder'), ' ·'),
                'href' => route('admin.media', ['in' => $a->container(), 'file' => $a->path()]),
                'tone' => 'var(--sky)',
                'icon' => 'image',
                'thumb' => $this->thumb($a),
            ])->values()->all()];
        }

        // People
        $people = $wordsOnly && $this->needle() !== ''
            ? User::query()->where(fn ($q) => $q->where('name', 'like', "%{$this->query}%")->orWhere('email', 'like', "%{$this->query}%"))->limit(20)->get()
            : collect();
        $counts['people'] = $people->count();
        if ($people->isNotEmpty() && $this->wants('people')) {
            $groups[] = ['key' => 'people', 'name' => 'People', 'items' => $people->take($this->limit())->map(fn (User $u) => [
                'label' => $u->name,
                'meta' => $u->roleName().' · '.$u->presenceLabel(),
                'href' => route('admin.people', ['person' => $u->id]),
                'tone' => 'var(--lilac)',
                'icon' => 'user',
                'initials' => Str::upper(Str::substr($u->name, 0, 1)),
            ])->all()];
        }

        // Tags & categories
        $terms = $wordsOnly && $this->needle() !== ''
            ? Booklet::terms()->all()->filter(fn (Term $t) => $this->matches($t->title().' '.$t->slug()))
            : collect();
        $counts['tags'] = $terms->count();
        if ($terms->isNotEmpty() && $this->wants('tags')) {
            $groups[] = ['key' => 'tags', 'name' => 'Tags & categories', 'items' => $terms->take($this->limit())->map(fn (Term $t) => [
                'label' => ($t->taxonomy() === 'tags' ? '#' : '').$t->title(),
                'meta' => Str::headline(Str::singular($t->taxonomy())),
                'href' => route('admin.taxonomies', ['in' => $t->taxonomy(), 'term' => $t->slug()]),
                'tone' => 'var(--coral)',
                'icon' => 'tag',
            ])->values()->all()];
        }

        // Chapters of the book
        $chapters = $wordsOnly ? $this->chapters()->filter(fn ($c) => $this->matches($c['label'].' '.$c['meta'])) : collect();
        $counts['go'] = $searching ? $chapters->count() : 0;
        if ($searching && $chapters->isNotEmpty() && $this->wants('go')) {
            $groups[] = ['key' => 'go', 'name' => 'Go to', 'items' => $chapters->take($this->limit())->values()->all()];
        }

        $counts['all'] = array_sum($counts);

        return ['groups' => $groups, 'counts' => $counts];
    }

    protected function wants(string $key): bool
    {
        return $this->scope === 'all' || $this->scope === $key;
    }

    protected function limit(): int
    {
        return $this->scope === 'all' ? self::PER_GROUP : 40;
    }

    public function entryItem(Entry $e, string $handle): array
    {
        $edited = $e->lastModified() ? Carbon::parse($e->lastModified())->diffForHumans(short: true) : null;

        return [
            'label' => $e->title() ?: 'Untitled',
            'meta' => trim(($e->uri() ?? '').($edited ? ' · edited '.$edited : ''), ' ·'),
            'href' => route('admin.entries.edit', [$handle, $e->id()]),
            'tone' => ['draft' => 'var(--sun)', 'scheduled' => 'var(--sky)', 'published' => 'var(--mint)'][$e->status()] ?? 'var(--sand)',
            'status' => $e->status(),
            'icon' => 'pen',
        ];
    }

    protected function thumb(Asset $asset): ?string
    {
        if (! $asset->isImage()) {
            return null;
        }

        try {
            $disk = Booklet::assetContainers()->find($asset->container())?->attribute('disk') ?? 'public';

            return \App\Support\Disk::of($disk)->url($asset->path());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every chapter of the book, as places to go.
     */
    public function chapters(): Collection
    {
        $places = collect(AdminNav::tableOfContents())
            ->filter(fn ($line) => ! empty($line['href']))
            ->map(fn ($line) => [
                'label' => $line['title'],
                'meta' => trim(($line['chapter'] ? $line['chapter'].' · ' : '').__('page :n', ['n' => $line['page']])),
                'href' => $line['href'],
                'tone' => 'var(--'.$line['tone'].')',
                'icon' => 'book',
            ])
            ->merge([
                ['label' => __('Calendar'), 'meta' => __('What goes out when'), 'href' => route('admin.calendar'), 'tone' => 'var(--sky)', 'icon' => 'calendar'],
                ['label' => __('Categories & tags'), 'meta' => __('The index of your blog'), 'href' => route('admin.taxonomies'), 'tone' => 'var(--rose)', 'icon' => 'tag'],
                ['label' => __('Site menus'), 'meta' => __('Header and footer links'), 'href' => route('admin.menus'), 'tone' => 'var(--mint)', 'icon' => 'list'],
                ['label' => __('Visits'), 'meta' => __('Most read, where readers come from'), 'href' => route('admin.visits'), 'tone' => 'var(--sky)', 'icon' => 'pulse'],
                ['label' => __('The bin'), 'meta' => __('Put back what was deleted'), 'href' => route('admin.bin'), 'tone' => 'var(--sand)', 'icon' => 'bin'],
                ['label' => __('Inbox'), 'meta' => __('Messages and form answers'), 'href' => route('admin.inbox'), 'tone' => 'var(--rose)', 'icon' => 'mail'],
                ['label' => __('Forms'), 'meta' => __('Sign-ups, RSVPs, orders: forms of your own'), 'href' => route('admin.forms'), 'tone' => 'var(--rose)', 'icon' => 'list'],
                ['label' => __('Subscribers'), 'meta' => __('Readers who get new posts by email'), 'href' => route('admin.subscribers'), 'tone' => 'var(--lilac)', 'icon' => 'comment'],
                ['label' => __('Redirects'), 'meta' => __('Old addresses that forward to new ones'), 'href' => route('admin.redirects'), 'tone' => 'var(--mint)', 'icon' => 'redirect'],
                ['label' => __('Print as a book'), 'meta' => __('Your posts typeset for paper, or a PDF'), 'href' => route('admin.print'), 'tone' => 'var(--sun)', 'icon' => 'printer'],
            ])
            ->when(request()->user()?->isAdmin(), fn ($c) => $c->push(
                ['label' => __('Email'), 'meta' => __('The mail server the book sends through (SMTP)'), 'href' => route('admin.settings.email'), 'tone' => 'var(--sand)', 'icon' => 'mail'],
                ['label' => __('Check-up'), 'meta' => __('Is the server giving the book everything it needs?'), 'href' => route('admin.settings.health'), 'tone' => 'var(--mint)', 'icon' => 'pulse'],
                ['label' => __('How Stacy behaves'), 'meta' => __('Answer length, tone, limits'), 'href' => route('admin.settings.stacy.behaviour'), 'tone' => 'var(--ink)', 'icon' => 'stacy'],
                ['label' => __('Backups'), 'meta' => __('Download the whole book, or restore it'), 'href' => route('admin.backups'), 'tone' => 'var(--sand)', 'icon' => 'book'],
                ['label' => __('Move in from WordPress'), 'meta' => __('Import posts, pages, pictures and comments'), 'href' => route('admin.import'), 'tone' => 'var(--sand)', 'icon' => 'upload'],
                ['label' => __('Inserts'), 'meta' => __('Extras for the book: switch them on and off'), 'href' => route('admin.settings.inserts'), 'tone' => 'var(--sun)', 'icon' => 'layers'],
                ['label' => __('Webhooks'), 'meta' => __('Rebuild a static site, tell Slack or Discord, start a Zap'), 'href' => route('admin.settings.webhooks'), 'tone' => 'var(--sky)', 'icon' => 'redirect'],
                ['label' => __('Updates'), 'meta' => __('New versions of Booklet: what changed, update, go back'), 'href' => route('admin.settings.updates'), 'tone' => 'var(--sand)', 'icon' => 'history'],
            ))
            // Pages of the inserts that are switched on (for whoever may open them).
            ->merge(rescue(fn () => app(\App\Inserts\InsertManager::class)->active()
                ->filter(fn ($i) => $i->canOpen(request()->user()))
                ->map(fn ($i) => ['label' => $i->page()['title'], 'meta' => \Illuminate\Support\Str::limit($i->about() ?: __('An insert'), 60), 'href' => $i->pageUrl(), 'tone' => 'var(--'.$i->sticker().')', 'icon' => 'layers'])
                ->values()->all(), [], false))
;

        // Writers would only meet a locked door at these.
        if (! in_array(request()->user()?->level(), ['admin', 'editor'], true)) {
            $locked = [route('admin.inbox'), route('admin.subscribers'), route('admin.menus'), route('admin.redirects')];
            $places = $places->reject(fn ($place) => in_array($place['href'], $locked, true));
        }

        return $places->unique('href')->values();
    }

    /**
     * Things to do, shown before typing and matched by words like "new" or "upload".
     *
     * @return array<int, array{label: string, meta: string, href: string, icon: string, tone: string}>
     */
    public function actions(): array
    {
        $actions = [];

        foreach (Booklet::collections()->all()->sortBy(fn ($c) => $c->handle() === 'posts' ? 0 : 1) as $c) {
            $actions[] = [
                'label' => 'New '.Str::lower(Str::singular($c->title())),
                'meta' => 'Start writing in '.$c->title(),
                'href' => route('admin.entries.create', $c->handle()),
                'icon' => 'plus',
                'tone' => $c->get('sticker', 'var(--sand)'),
            ];
        }

        $actions[] = ['label' => 'Upload files', 'meta' => 'Add pictures or documents to Media', 'href' => route('admin.media'), 'icon' => 'upload', 'tone' => 'var(--sky)'];

        if (in_array(request()->user()?->level(), ['admin'], true)) {
            $actions[] = ['label' => 'Invite someone', 'meta' => 'Add a writer or editor', 'href' => route('admin.people').'#invite-name', 'icon' => 'people', 'tone' => 'var(--lilac)'];
        }

        $waiting = rescue(fn () => Comment::query()->where('status', 'waiting')->count(), 0, false);
        if ($waiting > 0) {
            $actions[] = ['label' => 'Read waiting comments', 'meta' => $waiting.' '.Str::plural('note', $waiting).' to approve', 'href' => route('admin.comments'), 'icon' => 'comment', 'tone' => 'var(--rose)'];
        }

        $actions[] = ['label' => 'Ask Stacy', 'meta' => 'Talk or type to your assistant', 'href' => route('admin.stacy'), 'icon' => 'stacy', 'tone' => 'var(--ink)'];
        $actions[] = ['label' => 'View the site', 'meta' => 'Opens in a new tab', 'href' => url('/'), 'icon' => 'expand', 'tone' => 'var(--mint)', 'external' => true];

        return $this->needle() === ''
            ? $actions
            : array_values(array_filter($actions, fn ($a) => $this->matches($a['label'].' '.$a['meta'])));
    }

    /**
     * The most recently edited entries, for before you type.
     */
    public function recentlyEdited(int $take = 5): array
    {
        return Booklet::entries()->latest($take)
            ->map(fn (Entry $e) => $this->entryItem($e, $e->collectionHandle()))
            ->values()
            ->all();
    }

    /**
     * The text with the words being looked for marked, safe to print.
     */
    public static function mark(string $text, string $needle): HtmlString
    {
        $safe = e($text);
        $needle = trim($needle);
        if ($needle === '') {
            return new HtmlString($safe);
        }

        return new HtmlString((string) preg_replace('/('.preg_quote(e($needle), '/').')/iu', '<mark>$1</mark>', $safe));
    }
}
