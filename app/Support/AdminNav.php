<?php

namespace App\Support;

use App\Models\Comment;
use Throwable;

/**
 * Every chapter of the admin, for the phone and tablet menus: where it goes,
 * its sticker colour and icon, and whether the current page is in it.
 */
class AdminNav
{
    /**
     * @return array<int, array{key: string, label: string, hint: string, href: string, icon: string, tone: string, active: bool, badge: ?int, group: string}>
     */
    /**
     * The page being looked at. During a Livewire update (a button pressed
     * on the page) the request is Livewire's own, so the page's address is
     * matched again: otherwise chapter labels, page numbers and the lit flag
     * would vanish after the first click.
     */
    public static function page(): \Illuminate\Http\Request
    {
        $request = request();
        if ($request->attributes->has('booklet.page')) {
            return $request->attributes->get('booklet.page');
        }

        $page = $request;
        if (class_exists(\Livewire\Livewire::class) && \Livewire\Livewire::isLivewireRequest()) {
            try {
                $original = \Illuminate\Http\Request::create((string) \Livewire\Livewire::originalUrl(), 'GET');
                $route = app('router')->getRoutes()->match($original);
                $original->setRouteResolver(fn () => $route->bind($original));
                $page = $original;
            } catch (\Throwable) {
                $page = $request;
            }
        }

        $request->attributes->set('booklet.page', $page);

        return $page;
    }

    public static function chapters(): array
    {
        $r = static::page();
        $user = request()->user();
        $arranges = in_array($user?->level(), ['admin', 'editor'], true);

        try {
            $waiting = Comment::where('status', 'waiting')->count();
        } catch (Throwable) {
            $waiting = 0;
        }

        $items = [
            ['contents', 'Contents', 'The dashboard', route('admin.contents'), 'book', 'coral', $r->routeIs('admin.contents'), null, 'main'],
            ($user?->canOpen('posts') ?? true) ? ['posts', 'Posts', 'Write and publish', route('admin.entries', 'posts'), 'pen', 'sun', $r->route('collection') === 'posts', null, 'main'] : null,
            ($user?->canOpen('pages') ?? true) ? ['pages', 'Pages', 'Your site’s pages', route('admin.entries', 'pages'), 'file', 'mint', $r->route('collection') === 'pages', null, 'main'] : null,
            ['media', 'Media', 'Pictures and files', route('admin.media'), 'image', 'sky', $r->routeIs('admin.media'), null, 'main'],
            ['people', 'People', 'Who writes here', route('admin.people'), 'people', 'lilac', $r->routeIs('admin.people'), null, 'main'],
            $arranges ? ['subscribers', 'Subscribers', 'New posts by email', route('admin.subscribers'), 'comment', 'lilac', $r->routeIs('admin.subscribers'), null, 'tools'] : null,
            ['comments', 'Comments', 'Notes from readers', route('admin.comments'), 'comment', 'rose', $r->routeIs('admin.comments'), $waiting ?: null, 'main'],
            $arranges ? ['inbox', 'Inbox', 'Messages from the contact form', route('admin.inbox'), 'mail', 'rose', $r->routeIs('admin.inbox'), \App\Support\Inbox::newCount() ?: null, 'tools'] : null,
            ['calendar', 'Calendar', 'What goes out when', route('admin.calendar'), 'calendar', 'sky', $r->routeIs('admin.calendar'), null, 'tools'],
            ['taxonomies', 'Categories & tags', 'The index', route('admin.taxonomies'), 'tag', 'rose', $r->routeIs('admin.taxonomies'), null, 'tools'],
            ['print', 'Print as a book', 'Your writing, typeset for paper', route('admin.print'), 'printer', 'sun', $r->routeIs('admin.print'), null, 'tools'],
            $arranges ? ['menus', 'Site menus', 'Header and footer', route('admin.menus'), 'list', 'mint', $r->routeIs('admin.menus'), null, 'tools'] : null,
            $arranges ? ['redirects', 'Redirects', 'Old addresses that forward', route('admin.redirects'), 'redirect', 'mint', $r->routeIs('admin.redirects'), null, 'tools'] : null,
            ['activity', 'Activity', 'Who changed what', route('admin.activity'), 'pulse', 'coral', $r->routeIs('admin.activity'), null, 'tools'],
            ['bin', 'The bin', 'Put back what was deleted', route('admin.bin'), 'bin', 'sand', $r->routeIs('admin.bin'), null, 'tools'],
            ['collections', 'Collections', 'Chapters & blueprints', route('admin.collections'), 'layers', 'sand', $r->routeIs('admin.collections'), null, 'tools'],
            ['settings', 'Settings', 'Site, look & Stacy', route('admin.settings'), 'settings', 'sand', $r->routeIs('admin.settings', 'admin.settings.*'), null, 'you'],
            $user?->isAdmin() ? ['backups', 'Backups', 'The whole book in one file', route('admin.backups'), 'book', 'sand', $r->routeIs('admin.backups'), null, 'you'] : null,
            $user?->isAdmin() ? ['version-control', 'Version control', 'Every change kept in git', route('admin.settings.version-control'), 'history', 'sand', $r->routeIs('admin.settings.version-control'), null, 'you'] : null,
            $user?->isAdmin() ? ['look', 'The cover', 'Fonts, colours & layout', route('admin.settings.look'), 'book', 'sand', $r->routeIs('admin.settings.look'), null, 'you'] : null,
            $user?->isAdmin() ? ['static', 'Publish as plain files', 'For GitHub Pages or any host', route('admin.settings.static'), 'printer', 'sand', $r->routeIs('admin.settings.static'), null, 'you'] : null,
            $user?->isAdmin() ? ['api', 'Content API', 'Your posts as JSON for apps', route('admin.settings.api'), 'code', 'sand', $r->routeIs('admin.settings.api'), null, 'you'] : null,
            $user?->isAdmin() ? ['import', 'Move in from WordPress', 'Bring an old site across', route('admin.import'), 'upload', 'sand', $r->routeIs('admin.import'), null, 'you'] : null,
            ['account', 'Your account', 'Profile & password', route('admin.account'), 'user', 'lilac', $r->routeIs('admin.account'), null, 'you'],
        ];

        return array_values(array_map(fn ($i) => [
            'key' => $i[0], 'label' => $i[1], 'hint' => $i[2], 'href' => $i[3], 'icon' => $i[4],
            'tone' => $i[5], 'active' => (bool) $i[6], 'badge' => $i[7], 'group' => $i[8],
        ], array_filter($items)));
    }

    /**
     * The chapter the current page belongs to, for the top bar's title.
     */
    public static function current(): string
    {
        $r = static::page();

        foreach (static::chapters() as $chapter) {
            if ($chapter['active']) {
                return $chapter['label'];
            }
        }

        return match (true) {
            $r->routeIs('admin.stacy') => 'Ask Stacy',
            $r->routeIs('admin.entries.*') => 'Writing',
            default => 'Booklet',
        };
    }

    /**
     * Where the current page sits in the book, so turning to a later
     * chapter flips forward and to an earlier one flips back.
     */
    public static function position(): int
    {
        $r = static::page();

        if ($r->routeIs('admin.stacy')) {
            return 0;
        }

        foreach (static::chapters() as $i => $chapter) {
            if ($chapter['active']) {
                // The editor and history sit just after their chapter's list.
                return ($i + 1) * 10 + ($r->routeIs('admin.entries.create', 'admin.entries.edit') ? 1 : 0)
                    + ($r->routeIs('admin.entries.editions') ? 2 : 0);
            }
        }

        return 500;
    }

    // The book's structure ---------------------------------------------------

    /**
     * Every spread in the book, in reading order, with real page numbers:
     * front matter in roman numerals (i, ii…), then the chapters and the
     * appendices in arabic numerals. A spread is two facing pages, so the
     * left page is always even and the right page odd, as in a printed book.
     * Each collection is its own chapter, so binding a new one adds a chapter.
     *
     * @return array<int, array{key: string, part: string, chapter: ?string, chapterTitle: string, title: string, href: ?string, tone: string, left: string, right: string}>
     */
    public static function book(): array
    {
        // Worked out once per request (collections can change between requests).
        if ($cached = request()->attributes->get('booklet.book')) {
            return $cached;
        }

        $spreads = [];
        $add = function (string $key, string $part, ?string $chapter, string $chapterTitle, string $title, ?string $href, string $tone) use (&$spreads) {
            $spreads[] = compact('key', 'part', 'chapter', 'chapterTitle', 'title', 'href', 'tone');
        };

        // Front matter
        $add('contents', 'front', null, 'Contents', 'Contents', route('admin.contents'), 'coral');
        $add('stacy', 'front', null, 'Ask Stacy', 'Ask Stacy', route('admin.stacy'), 'ink');

        // Chapters: each collection, then Media, People, Comments
        $n = 0;
        try {
            $collections = \App\Booklet\Facades\Booklet::collections()->all()
                ->filter(fn ($c) => request()->user()?->canOpen($c->handle()) ?? true) // chapters their role opens
                ->sortBy(fn ($c) => [$c->handle() === 'posts' ? 0 : ($c->handle() === 'pages' ? 1 : 2), $c->title()])
                ->values();
        } catch (\Throwable) {
            $collections = collect();
        }

        $hasCalendar = $hasMenus = false;
        foreach ($collections as $collection) {
            $chapter = 'Chapter '.(++$n);
            $handle = $collection->handle();
            $tone = $handle === 'posts' ? 'sun' : ($handle === 'pages' ? 'mint' : 'sand');
            $add("list:{$handle}", 'main', $chapter, $collection->title(), $collection->title(), route('admin.entries', $handle), $tone);
            $add("editor:{$handle}", 'main', $chapter, $collection->title(), 'Writing', null, $tone);
            $add("history:{$handle}", 'main', $chapter, $collection->title(), 'Editions', null, $tone);
            // The calendar and the index belong to the blog; the menus to the site's pages.
            // If there's no "posts" or "pages", the first dated or structured collection takes them.
            if (! $hasCalendar && ($handle === 'posts' || ($collection->dated() && ! $collections->contains(fn ($c) => $c->handle() === 'posts')))) {
                $add('calendar', 'main', $chapter, $collection->title(), 'Calendar', route('admin.calendar'), $tone);
                $add('taxonomies', 'main', $chapter, $collection->title(), 'Categories & tags', route('admin.taxonomies'), $tone);
                $add('print', 'main', $chapter, $collection->title(), 'Print as a book', route('admin.print'), $tone);
                $hasCalendar = true;
            }
            if (! $hasMenus && ($handle === 'pages' || ($collection->structured() && ! $collections->contains(fn ($c) => $c->handle() === 'pages')))) {
                $add('menus', 'main', $chapter, $collection->title(), 'Site menus', route('admin.menus'), $tone);
                $add('redirects', 'main', $chapter, $collection->title(), 'Redirects', route('admin.redirects'), $tone);
                $hasMenus = true;
            }
        }

        // Still without a home (no collections at all, say): give them a chapter each, so every screen has a page.
        if (! $hasCalendar) {
            $chapter = 'Chapter '.(++$n);
            $add('calendar', 'main', $chapter, 'Calendar', 'Calendar', route('admin.calendar'), 'sun');
            $add('taxonomies', 'main', $chapter, 'Calendar', 'Categories & tags', route('admin.taxonomies'), 'sun');
            $add('print', 'main', $chapter, 'Calendar', 'Print as a book', route('admin.print'), 'sun');
        }
        if (! $hasMenus) {
            $add('menus', 'main', 'Chapter '.(++$n), 'Site menus', 'Site menus', route('admin.menus'), 'mint');
            $add('redirects', 'main', 'Chapter '.$n, 'Site menus', 'Redirects', route('admin.redirects'), 'mint');
        }

        $add('media', 'main', 'Chapter '.(++$n), 'Media', 'Media', route('admin.media'), 'sky');
        $add('people', 'main', 'Chapter '.(++$n), 'People', 'People', route('admin.people'), 'lilac');
        $add('subscribers', 'main', 'Chapter '.$n, 'People', 'Subscribers', route('admin.subscribers'), 'lilac');
        $add('comments', 'main', 'Chapter '.(++$n), 'Comments', 'Comments', route('admin.comments'), 'rose');
        $add('inbox', 'main', 'Chapter '.$n, 'Comments', 'Inbox', route('admin.inbox'), 'rose');

        // Back matter
        $add('activity', 'back', 'Appendix A', 'Activity', 'Activity', route('admin.activity'), 'coral');
        $add('visits', 'back', 'Appendix A', 'Activity', 'Visits', route('admin.visits'), 'sky');
        $add('bin', 'back', 'Appendix A', 'Activity', 'The bin', route('admin.bin'), 'sand');
        $add('collections', 'back', 'Appendix B', 'Collections', 'Collections & blueprints', route('admin.collections'), 'sand');
        $add('settings', 'back', 'Appendix C', 'Settings', 'Settings', route('admin.settings'), 'sand');
        $add('email', 'back', 'Appendix C', 'Settings', 'Email', route('admin.settings.email'), 'sand');
        $add('backups', 'back', 'Appendix C', 'Settings', 'Backups', route('admin.backups'), 'sand');
        $add('health', 'back', 'Appendix C', 'Settings', 'Check-up', route('admin.settings.health'), 'sand');
        $add('version-control', 'back', 'Appendix C', 'Settings', 'Version control', route('admin.settings.version-control'), 'sand');
        $add('static', 'back', 'Appendix C', 'Settings', 'Publish as plain files', route('admin.settings.static'), 'sand');
        $add('look', 'back', 'Appendix C', 'Settings', 'The cover', route('admin.settings.look'), 'sand');
        $add('api', 'back', 'Appendix C', 'Settings', 'Content API', route('admin.settings.api'), 'sand');
        $add('import', 'back', 'Appendix C', 'Settings', 'Move in from WordPress', route('admin.import'), 'sand');
        $add('inserts', 'back', 'Appendix C', 'Settings', 'Inserts', route('admin.settings.inserts'), 'sand');
        $add('stacy-settings', 'back', 'Appendix D', 'Stacy’s brain', 'Stacy’s brain', route('admin.settings.stacy'), 'ink');
        $add('stacy-behaviour', 'back', 'Appendix D', 'Stacy’s brain', 'How Stacy behaves', route('admin.settings.stacy.behaviour'), 'ink');
        $add('account', 'back', 'Ex libris', 'Your account', 'Your account', route('admin.account'), 'lilac');

        // Inserts' pages: tucked in after the chapter they name, or into one of their own.
        $spreads = static::tuckInserts($spreads);

        // Page numbers
        $front = 1;
        $page = 2;
        foreach ($spreads as $i => $spread) {
            if ($spread['part'] === 'front') {
                $spreads[$i]['left'] = static::roman($front++);
                $spreads[$i]['right'] = static::roman($front++);
            } else {
                $spreads[$i]['left'] = (string) $page++;
                $spreads[$i]['right'] = (string) $page++;
            }
        }

        request()->attributes->set('booklet.book', $spreads);

        return $spreads;
    }

    /**
     * @param  array<int, array>  $spreads
     * @return array<int, array>
     */
    protected static function tuckInserts(array $spreads): array
    {
        $inserts = rescue(fn () => app(\App\Inserts\InsertManager::class)->active()->filter(fn ($i) => $i->page() !== null), collect(), false);
        $loose = [];

        foreach ($inserts as $insert) {
            $page = $insert->page();
            $spread = ['key' => 'insert:'.$insert->key, 'part' => 'main', 'chapter' => null, 'chapterTitle' => $page['title'],
                'title' => $page['title'], 'href' => route('admin.insert', $insert->key), 'tone' => $insert->sticker()];

            // After the last spread of the chapter it names ("Media", "Posts", "People"…).
            $after = null;
            foreach ($spreads as $i => $s) {
                if ($page['chapter'] !== '' && mb_strtolower($s['chapterTitle']) === mb_strtolower($page['chapter'])) {
                    $after = $i;
                }
            }
            if ($after !== null) {
                $spread['part'] = $spreads[$after]['part'];
                $spread['chapter'] = $spreads[$after]['chapter'];
                $spread['chapterTitle'] = $spreads[$after]['chapterTitle'];
                array_splice($spreads, $after + 1, 0, [$spread]);
            } else {
                $loose[] = $spread;
            }
        }

        // The rest share an appendix of their own, before Ex libris.
        if ($loose !== []) {
            $at = count($spreads);
            foreach ($spreads as $i => $s) {
                if ($s['key'] === 'account') {
                    $at = $i;
                }
            }
            $loose = array_map(fn ($s) => ['part' => 'back', 'chapter' => 'Appendix E', 'chapterTitle' => 'Inserts'] + $s, $loose);
            array_splice($spreads, $at, 0, $loose);
        }

        return $spreads;
    }

    public static function roman(int $n): string
    {
        $map = ['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1];
        $out = '';
        foreach ($map as $numeral => $value) {
            while ($n >= $value) {
                $out .= $numeral;
                $n -= $value;
            }
        }

        return $out;
    }

    /**
     * The spread the current page is showing.
     */
    public static function spread(): ?array
    {
        $r = static::page();
        $collection = $r->route('collection');
        $collection = is_object($collection) ? null : $collection;

        $key = match (true) {
            $r->routeIs('admin.contents') => 'contents',
            $r->routeIs('admin.stacy') => 'stacy',
            $r->routeIs('admin.entries.editions') => "history:{$collection}",
            $r->routeIs('admin.entries.create', 'admin.entries.edit') => "editor:{$collection}",
            $r->routeIs('admin.entries') => "list:{$collection}",
            $r->routeIs('admin.settings.email') => 'email',
            $r->routeIs('admin.settings.health') => 'health',
            $r->routeIs('admin.settings.version-control') => 'version-control',
            $r->routeIs('admin.settings.static', 'admin.settings.static.*') => 'static',
            $r->routeIs('admin.settings.look') => 'look',
            $r->routeIs('admin.settings.api') => 'api',
            $r->routeIs('admin.settings.inserts') => 'inserts',
            $r->routeIs('admin.insert') => 'insert:'.$r->route('insert'),
            $r->routeIs('admin.settings.stacy') => 'stacy-settings',
            $r->routeIs('admin.settings.stacy.behaviour') => 'stacy-behaviour',
            default => str_replace('admin.', '', (string) $r->route()?->getName()),
        };

        foreach (static::book() as $spread) {
            if ($spread['key'] === $key) {
                return $spread;
            }
        }

        // A screen the book doesn't list (a new collection mid-request, a stray route):
        // an unnumbered loose leaf rather than nothing, so folios and labels never break.
        if ($r->routeIs('admin.*')) {
            $title = static::current();

            return ['key' => $key, 'part' => 'loose', 'chapter' => null, 'chapterTitle' => $title, 'title' => $title,
                'href' => null, 'tone' => 'sand', 'left' => '', 'right' => ''];
        }

        return null;
    }

    /**
     * The page number for one side of the current spread.
     */
    public static function folio(string $side = 'left'): string
    {
        $spread = static::spread();

        return $spread ? $spread[$side === 'right' ? 'right' : 'left'] : '';
    }

    /**
     * "Chapter 1", "Appendix C", "Ex libris", or "" in the front matter.
     */
    public static function chapterLabel(): string
    {
        return (string) (static::spread()['chapter'] ?? '');
    }

    /**
     * The table of contents: one line per chapter (its first spread).
     *
     * @return array<int, array{key: string, chapter: ?string, title: string, href: ?string, tone: string, page: string, part: string}>
     */
    public static function tableOfContents(): array
    {
        $seen = [];
        $lines = [];

        foreach (static::book() as $spread) {
            $id = $spread['chapter'] ?? $spread['key'];
            if (isset($seen[$id]) || $spread['href'] === null || $spread['key'] === 'contents') {
                continue;
            }
            $seen[$id] = true;
            $lines[] = [
                'key' => $spread['key'],
                'chapter' => $spread['chapter'],
                'title' => $spread['chapterTitle'],
                'href' => $spread['href'],
                'tone' => $spread['tone'],
                'page' => $spread['left'],
                'part' => $spread['part'],
            ];
        }

        return $lines;
    }
}
