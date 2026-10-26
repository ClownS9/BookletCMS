<?php

namespace App\PageBuilder;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Models\ActivityLog;
use App\Models\PageBuild;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Building pages, posts and the blog page out of sections, on the page
 * itself. Signed in, open one and press "Edit this page" (or "Build this
 * page", the first time): click words to type into them, add, move and
 * dress sections, then publish, or schedule. The page or post stays what it
 * is (its address, menus, chapter); only how it's drawn changes. Readers
 * only ever see what was published, and until the first publish, the page
 * as Booklet draws it.
 *
 * The blog page is arranged the same way, with its own sections (Featured
 * story, Categories, The posts) among the others; until then it follows
 * The cover's home layout.
 */
class PageBuilder
{
    public function __construct(public readonly Builder $builder)
    {
        // Pictures come from Media only.
        Fields::$pictures ??= function (string $path): ?array {
            static $seen = [];
            if (! array_key_exists($path, $seen)) {
                $asset = rescue(fn () => Booklet::assets()->find(['container' => 'media', 'path' => $path]), null, false);
                $seen[$path] = $asset && $asset->isImage() ? \App\Support\Blocks::picture($path) : null;
            }

            return $seen[$path];
        };
    }

    public function isPicture(string $path): bool
    {
        return Fields::pictureFor($path) !== null;
    }

    /** The blog's categories, for "Latest posts". */
    public function categories(): array
    {
        return ['' => 'Every category'] + rescue(fn () => Booklet::terms()->whereTaxonomy('categories')
            ->mapWithKeys(fn ($t) => [(string) $t->slug() => (string) $t->title()])->all(), [], false);
    }

    // What can be built, and by whom ---------------------------------------------------------

    /** Does the theme the site wears draw this itself (its own template)? Then it's built in the theme instead. */
    public function themeDraws(string $template): bool
    {
        $theme = rescue(fn () => \App\Themes\ThemeManager::current(), null, false);

        return $theme !== null && in_array($template, $theme->templates(), true);
    }

    /** A page or a post that can be built here. */
    public function buildable(?Entry $entry): bool
    {
        if (! $entry || ! $entry->id() || ! $entry->collection()) {
            return false;
        }

        return ! $this->themeDraws($entry->collection()->dated() ? 'site.post' : 'site.page');
    }

    public function blogBuildable(): bool
    {
        return ! $this->themeDraws('site.blog');
    }

    /** Who may build it: people who can publish in its chapter; the blog page, editors and admins. */
    public function canBuild(?User $user, ?Entry $entry): bool
    {
        if (! $user) {
            return false;
        }

        return $entry ? $this->buildable($entry) && $user->canPublishIn($entry->collectionHandle()) : $this->blogBuildable() && $user->arranges();
    }

    public function canBuildThis(?User $user, PageBuild $page): bool
    {
        return $page->isBlog() ? $this->canBuild($user, null) : ($page->entry() !== null && $this->canBuild($user, $page->entry()));
    }

    // A page's sections, and where it stands -------------------------------------------------

    public function isPost(PageBuild $page): bool
    {
        return (bool) $page->entry()?->collection()?->dated();
    }

    /** The kinds of section a page can have (the blog's own only on the blog page). */
    public function typesFor(PageBuild $page): array
    {
        return array_filter($this->builder->types(), fn ($t) => empty($t['blog']) || $page->isBlog());
    }

    /** A page's sections, made safe to draw: the blog page always has its posts (once); other pages never have the blog's own. */
    public function sectionsOf(PageBuild $page, bool $draft = true): array
    {
        $builder = $this->builder;
        $sections = $builder->clean($draft ? $page->draft : $page->published);
        if (! $page->isBlog()) {
            return array_values(array_filter($sections, fn ($s) => empty($builder->type($s['type'])['blog'])));
        }
        $seen = [];
        $sections = array_values(array_filter($sections, function ($s) use ($builder, &$seen) {
            $one = ! empty($builder->type($s['type'])['required']) || ! empty($builder->type($s['type'])['once']);
            if ($one && isset($seen[$s['type']])) {
                return false;
            }
            $seen[$s['type']] = true;

            return true;
        }));
        if (! isset($seen['blog-posts'])) {
            $sections[] = $builder->make('blog-posts');
        }

        return $sections;
    }

    /** When something is scheduled for, in the site's time: "Sat 1 Nov, 9:00am". */
    public function when(string|\DateTimeInterface $at): string
    {
        return Carbon::parse($at)->timezone(config('app.timezone'))->format('D j M, g:ia');
    }

    /** Can readers see it: the page or post is published (the blog page: its arrangement is). */
    public function onSite(PageBuild $page): bool
    {
        return $page->isBlog() ? $page->published !== null : (bool) $page->entry()?->published();
    }

    /** Where it stands: [draft|changed|live|scheduled, words]. */
    public function status(PageBuild $page): array
    {
        $live = $this->onSite($page);
        if ($page->scheduled_at) {
            return ['scheduled', ($live ? 'Changes scheduled for ' : 'Scheduled for ').$this->when($page->scheduled_at)];
        }
        if (! $live) {
            return ['draft', $page->isBlog() ? 'Following The cover' : 'Not on the site'];
        }
        if ($page->published === null || $this->sectionsOf($page) != $this->sectionsOf($page, false)) {
            return ['changed', 'Changes not published'];
        }

        return ['live', $page->isBlog() ? 'As readers see it' : 'On the site'];
    }

    public function isHome(?Entry $entry): bool
    {
        return $entry !== null && (string) app(Site::class)->setting('homepage', 'posts') === $entry->id();
    }

    /** Its address for readers. */
    public function addressOf(PageBuild $page): string
    {
        if ($page->isBlog()) {
            return '/'.trim((string) parse_url(app(Site::class)->blogUrl(), PHP_URL_PATH), '/');
        }
        $entry = $page->entry();

        return ! $entry ? '' : ($this->isHome($entry) ? '/' : (string) $entry->uri());
    }

    /** Where to build it: on the page (a draft: its preview, which only people signed in can open). */
    public function editUrl(PageBuild|Entry $page): string
    {
        if ($page instanceof PageBuild && $page->isBlog()) {
            return app(Site::class)->blogUrl().'?edit=1';
        }
        $entry = $page instanceof Entry ? $page : $page->entry();
        if (! $entry) {
            return url('/');
        }

        return $entry->published() ? url($this->isHome($entry) ? '/' : (string) $entry->uri()).'?edit=1' : route('preview', (string) $entry->id()).'?edit=1';
    }

    // Starting ------------------------------------------------------------------------------

    /**
     * Start building a page or post: its own words, headings, pictures and
     * cut-outs as sections to start from (an empty one: a landing page with
     * its title). Nothing changes for readers until it's published.
     */
    public function start(Entry $entry): PageBuild
    {
        if ($page = PageBuild::forEntry($entry)) {
            return $page;
        }
        $empty = trim((string) $entry->get('content')) === '' && (array) $entry->get('blocks', []) === [];
        $post = (bool) $entry->collection()?->dated();
        $sections = $empty && ! $post ? $this->builder->starter('landing') : $this->fromEntry($entry);
        if ($post && ($sections[0]['type'] ?? '') === 'hero') {
            // A post's head has its title (and standfirst) already; an opening line stays, as words.
            $lede = trim((string) array_shift($sections)['fields']['lede']);
            if ($lede !== '' && $lede !== trim((string) $entry->get('excerpt'))) {
                $words = $this->builder->make('text-picture');
                $words['fields'] = array_merge($words['fields'], ['eyebrow' => '', 'title' => '', 'body' => $lede, 'picture' => '']);
                $words['look']['space'] = 'snug';
                array_unshift($sections, $words);
            }
        }
        if ($empty && $sections !== [] && $sections[0]['type'] === 'hero') {
            $sections[0]['fields']['title'] = $this->builder->line($entry->title(), 160);
        }

        return PageBuild::create(['target' => (string) $entry->id(), 'draft' => $sections, 'history' => [], 'fresh' => $empty]);
    }

    /** The blog page's build, made (as The cover has it) the first time someone arranges it. */
    public function blogForEditing(): PageBuild
    {
        return PageBuild::blog() ?? PageBuild::create(['target' => PageBuild::BLOG, 'draft' => $this->blogFromCover(), 'history' => []]);
    }

    /** The blog as The cover's home layout draws it, as sections. */
    public function blogFromCover(): array
    {
        $builder = $this->builder;
        $home = (string) (\App\Support\Look::current()['home'] ?? 'feature');
        $heading = $builder->make('heading');
        $heading['fields'] = ['eyebrow' => 'The blog', 'title' => '', 'lede' => ''];
        $sections = [$heading];
        if ($home === 'feature') {
            $sections[] = $builder->make('blog-featured');
        }
        $sections[] = $builder->make('blog-categories');
        $posts = $builder->make('blog-posts');
        $posts['fields']['style'] = $home === 'list' ? 'list' : 'grid';
        $sections[] = $posts;
        $sections[] = $builder->make('subscribe');

        return $sections;
    }

    /**
     * What the blog's sections draw: this page of posts (in a category, maybe),
     * and the newest one large when there's a Featured story.
     */
    public function blogContext(Request $request, array $sections, bool $live = false): array
    {
        $site = app(Site::class);
        $category = (string) $request->query('category', '');
        $posts = $site->posts($category !== '' ? 'categories' : null, $category ?: null);
        $perPage = $site->perPage();
        $at = max(1, (int) $request->query('page', '1'));
        $paginator = new LengthAwarePaginator($posts->forPage($at, $perPage)->values(), $posts->count(), $perPage, $at,
            ['path' => $live ? $site->blogUrl() : \Illuminate\Pagination\Paginator::resolveCurrentPath(), 'query' => array_intersect_key($request->query(), ['category' => 1])]);
        $featured = collect($sections)->contains('type', 'blog-featured') && $at === 1 && $category === '' ? $posts->first() : null;
        $shown = $featured ? $paginator->getCollection()->reject(fn ($e) => $e->id() === $featured->id())->values() : $paginator->getCollection();
        $categories = Booklet::terms()->whereTaxonomy('categories');

        return [
            'site' => $site, 'featured' => $featured, 'posts' => $shown, 'paginator' => $paginator, 'category' => $category, 'categories' => $categories,
            'blog' => ['featured' => $featured, 'posts' => $shown, 'paginator' => $paginator, 'category' => $category, 'categories' => $categories],
        ];
    }

    /*
     * Starting from a page's (or post's) own words: its title, words,
     * headings, pictures and cut-outs, as sections, in order. Words become
     * plain text (a heading starts a new section; lists become a line each).
     * Cut-outs become their nearest section: a quote → Quotes, pictures →
     * Pictures, two columns → Columns, a call to action → Call to action, a
     * video → Video, a note → words with a label. Pictures that are in Media
     * come along; others are left behind.
     */
    public function fromEntry(Entry $entry): array
    {
        $builder = $this->builder;
        $isPicture = fn (string $path): bool => $this->isPicture($path);
        $mediaPath = function (string $src) use ($isPicture): ?string {
            $base = rtrim(\App\Support\Disk::of('public')->url(''), '/').'/';
            $path = parse_url($src, PHP_URL_PATH) ?: $src;
            foreach ([$base, (string) parse_url($base, PHP_URL_PATH)] as $prefix) {
                if ($prefix === '' || $prefix === '/') {
                    continue;
                }
                if (str_starts_with($src, $prefix)) {
                    $path = substr($src, strlen($prefix));
                    break;
                }
                if (str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                    break;
                }
            }
            $path = rawurldecode(ltrim($path, '/'));

            return $path !== '' && $isPicture($path) ? $path : null;
        };
        $media = fn ($p) => is_string($p) && $p !== '' && $isPicture($p) ? $p : null;
        $flat = fn (string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));

        $sections = [];
        // Closer together than new sections: it reads like the page it came from.
        $add = function (array $section) use (&$sections) {
            if (count($sections) < Builder::MAX_SECTIONS) {
                $section['look']['space'] = $section['type'] === 'hero' ? 'normal' : 'snug';
                $sections[] = $section;
            }
        };

        // The opening: its title, its line about it, its cover picture.
        $hero = $builder->make('hero');
        $cover = $media(((array) $entry->get('cover', []))[0] ?? null);
        $hero['fields'] = array_merge($hero['fields'], [
            'eyebrow' => '',
            'title' => $builder->line($entry->title(), 160),
            'lede' => $builder->words((string) ($entry->get('excerpt') ?? $entry->get('meta_description') ?? ''), 600),
            'button' => ['label' => '', 'url' => ''],
            'picture' => (string) $cover,
            'layout' => $cover ? 'side' : 'none',
        ]);
        $add($hero);

        // Words waiting to become a "Words and a picture" section.
        // The words waiting to become a section, and which side its picture goes.
        $w = new class
        {
            /** @var array{title: string, eyebrow: string, body: list<string>, picture: string}|null */
            public ?array $part = null;

            public string $side = 'right';
        };
        $flush = function () use ($w, $builder, $add) {
            if ($w->part === null || ($w->part['title'] === '' && $w->part['body'] === [] && $w->part['picture'] === '')) {
                $w->part = null;

                return;
            }
            $section = $builder->make('text-picture');
            $section['fields'] = array_merge($section['fields'], [
                'eyebrow' => $w->part['eyebrow'],
                'title' => $builder->line($w->part['title'], 160),
                'body' => $builder->words(implode("\n\n", $w->part['body']), 6000),
                'button' => ['label' => '', 'url' => ''],
                'picture' => $w->part['picture'],
                'side' => $w->side,
            ]);
            if ($w->part['picture'] !== '') {
                $w->side = $w->side === 'right' ? 'left' : 'right';
            }
            $add($section);
            $w->part = null;
        };
        $start = function (string $title = '', string $eyebrow = '') use ($w, $flush) {
            $flush();
            $w->part = ['title' => $title, 'eyebrow' => $eyebrow, 'body' => [], 'picture' => ''];
        };

        // Markdown, a block at a time (raw HTML left out).
        $words = function (string $markdown) use ($w, $start, $mediaPath, $flat) {
            $markdown = \App\Inserts\InsertManager::stripSlips($markdown);
            if (trim($markdown) === '') {
                return;
            }
            $html = (string) \Illuminate\Support\Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
            $doc = new \DOMDocument;
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8"?><div id="pb-root">'.$html.'</div>', LIBXML_NONET);
            libxml_clear_errors();
            $root = $doc->getElementById('pb-root');
            foreach ($root ? $root->childNodes : [] as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($node->tagName);
                if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                    $start($flat($node->textContent));

                    continue;
                }
                $w->part ??= ['title' => '', 'eyebrow' => '', 'body' => [], 'picture' => ''];
                foreach ($node->getElementsByTagName('img') as $img) {
                    if ($w->part['picture'] === '' && ($path = $mediaPath((string) $img->getAttribute('src')))) {
                        $w->part['picture'] = $path;
                    }
                }
                $text = $flat($node->textContent);
                if (in_array($tag, ['ul', 'ol'], true)) {
                    $lines = [];
                    foreach ($node->getElementsByTagName('li') as $li) {
                        $lines[] = '• '.$flat($li->textContent);
                    }
                    $text = implode("\n", $lines);
                }
                if ($text !== '') {
                    $w->part['body'][] = $text;
                }
            }
        };
        $plain = fn (string $markdown): string => trim(strip_tags((string) \Illuminate\Support\Str::markdown(
            \App\Inserts\InsertManager::stripSlips($markdown), ['html_input' => 'strip'])));

        $words((string) $entry->get('content', ''));

        // Its cut-outs (and tables), in the order its blueprint has them.
        foreach (\App\Support\Blocks::blueprintFields($entry) as $handle => $field) {
            $value = $entry->get($handle);
            if (($field['type'] ?? null) === 'grid' && is_array($value) && $value !== []) {
                $w->part ??= ['title' => '', 'eyebrow' => '', 'body' => [], 'picture' => ''];
                $w->part['body'][] = \App\Support\Rows::text($value, \App\Support\Rows::columns($field));

                continue;
            }
            if (($field['type'] ?? null) !== 'blocks' || ! is_array($value)) {
                continue;
            }
            foreach ($value as $block) {
                if (! is_array($block) || ! ($block['enabled'] ?? true)) {
                    continue;
                }
                switch ($block['type'] ?? '') {
                    case 'text':
                        $words((string) ($block['text'] ?? ''));
                        break;

                    case 'quote':
                        $flush();
                        $q = $builder->make('quotes');
                        $q['fields'] = ['title' => '', 'items' => [['words' => $builder->words((string) ($block['quote'] ?? ''), 2000),
                            'name' => $builder->line((string) ($block['cite'] ?? '')), 'role' => '', 'picture' => '']]];
                        $q['look']['background'] = 'none';
                        $add($q);
                        break;

                    case 'picture':
                        $pic = $media(((array) ($block['picture'] ?? []))[0] ?? ($block['picture'] ?? null));
                        if (! $pic) {
                            break;
                        }
                        if ($w->part !== null && $w->part['picture'] === '') {
                            $w->part['picture'] = $pic; // beside the words before it
                        } else {
                            $start();
                            $w->part['picture'] = $pic;
                            if (trim((string) ($block['caption'] ?? '')) !== '') {
                                $w->part['body'][] = $flat((string) $block['caption']);
                            }
                        }
                        break;

                    case 'gallery':
                        $pics = array_values(array_filter(array_map($media, (array) ($block['pictures'] ?? []))));
                        if ($pics !== []) {
                            $flush();
                            $g = $builder->make('gallery');
                            $g['fields'] = ['title' => $builder->line((string) ($block['caption'] ?? '')), 'pictures' => array_slice($pics, 0, 40),
                                'across' => count($pics) % 3 === 0 || count($pics) > 4 ? '3' : '2', 'captions' => false];
                            $add($g);
                        }
                        break;

                    case 'button':
                        $flush();
                        $c = $builder->make('cta');
                        $url = rescue(fn () => $builder->url((string) ($block['link'] ?? '')), '', false);
                        $c['fields'] = ['title' => $builder->line((string) ($block['heading'] ?? ''), 160), 'words' => $builder->words((string) ($block['text'] ?? '')),
                            'button' => ['label' => $builder->line((string) ($block['label'] ?? ''), 60), 'url' => $url], 'button2' => ['label' => '', 'url' => '']];
                        $add($c);
                        break;

                    case 'columns':
                        $flush();
                        $col = $builder->make('columns');
                        $item = function (string $markdown) use ($builder, $plain): array {
                            $title = preg_match('/^\s*#{1,6}\s*(.+)$/m', $markdown, $m) ? trim($m[1]) : '';
                            $rest = $title !== '' ? (string) preg_replace('/^\s*#{1,6}\s*.+$/m', '', $markdown, 1) : $markdown;

                            return ['mark' => '', 'title' => $builder->line($title), 'words' => $builder->words($plain($rest))];
                        };
                        $col['fields'] = ['title' => '', 'intro' => '', 'across' => '2',
                            'items' => [$item((string) ($block['left'] ?? '')), $item((string) ($block['right'] ?? ''))]];
                        $add($col);
                        break;

                    case 'video':
                        if (Builder::embed(trim((string) ($block['url'] ?? '')))) {
                            $flush();
                            $v = $builder->make('video');
                            $v['fields'] = ['title' => '', 'url' => trim((string) $block['url']), 'caption' => $builder->line((string) ($block['caption'] ?? ''), 300)];
                            $add($v);
                        }
                        break;

                    case 'note':
                        $flush();
                        $label = ['tip' => 'Tip', 'info' => 'Good to know', 'warning' => 'Careful'][$block['kind'] ?? 'tip'] ?? 'Tip';
                        $w->part = ['title' => '', 'eyebrow' => $label, 'body' => [$plain((string) ($block['text'] ?? ''))], 'picture' => ''];
                        $flush();
                        $sections[count($sections) - 1]['look']['background'] = 'soft'; // a box, as it was
                        break;

                    case 'featured':
                    case 'break':
                        break;

                    default:
                        // An insert's own cut-out: its words, at least.
                        $text = \App\Support\Blocks::text([$block]);
                        if (trim($text) !== '') {
                            $w->part ??= ['title' => '', 'eyebrow' => '', 'body' => [], 'picture' => ''];
                            $w->part['body'][] = $text;
                        }
                }
            }
        }
        $flush();

        // A short opening line with no heading of its own: under the title, where it stood.
        $first = $sections[1] ?? null;
        if ($first && $first['type'] === 'text-picture' && $sections[0]['fields']['lede'] === '' && $first['fields']['title'] === ''
            && $first['fields']['picture'] === '' && $first['fields']['eyebrow'] === '' && ! str_contains($first['fields']['body'], "\n") && mb_strlen($first['fields']['body']) <= 300) {
            $sections[0]['fields']['lede'] = $first['fields']['body'];
            array_splice($sections, 1, 1);
        }

        return $sections;
    }

    // Changing the draft --------------------------------------------------------------------

    /**
     * Change a page's draft, keeping the one before for Undo. Typing into the
     * same words keeps adding to one Undo step.
     */
    public function change(PageBuild $page, callable $edit, ?string $mark = null): array
    {
        $before = $this->sectionsOf($page);
        $after = array_values($edit($before));
        if ($after == $before) {
            return $after;
        }
        $history = (array) $page->history;
        $last = (array) $page->history_mark;
        $same = $mark !== null && ($last['mark'] ?? null) === $mark && now()->timestamp - (int) ($last['at'] ?? 0) < 30;
        if (! $same) {
            $history[] = $before;
            $history = array_slice($history, -30);
        }
        $page->put(['draft' => $after, 'history' => $history, 'history_mark' => ['mark' => $mark, 'at' => now()->timestamp]]);

        return $after;
    }

    /** Find a section in a page's draft: [index, section], or [null, null]. */
    public function locate(array $sections, mixed $id): array
    {
        foreach ($sections as $i => $s) {
            if ($s['id'] === $id) {
                return [$i, $s];
            }
        }

        return [null, null];
    }

    /** Run a change to one section, saying plainly when it can't be done. */
    public function onSection(PageBuild $page, mixed $id, callable $edit, ?string $mark = null): ?string
    {
        try {
            $this->change($page, function (array $sections) use ($id, $edit) {
                [$i, $s] = $this->locate($sections, $id);
                if ($i === null) {
                    throw new InvalidArgumentException('That section isn’t on the page any more.');
                }
                $sections[$i] = $edit($s);

                return $sections;
            }, $mark);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    // Publishing ----------------------------------------------------------------------------

    /**
     * Put sections on the site: the blog's arrangement, or a page's (and the
     * page itself, if it's a draft). A page that started empty also gets its
     * words as plain text, should it ever be drawn without its sections.
     */
    public function goLive(PageBuild $page, array $sections, Carbon $at, bool $scheduled = false): void
    {
        $page->put(['published' => $sections, 'published_at' => $at, 'scheduled' => null]);
        $note = $scheduled ? ' (scheduled)' : '';
        if ($page->isBlog()) {
            ActivityLog::record('arranged the blog page'.$note, 'publishing', 'builder', PageBuild::BLOG, 'The blog');

            return;
        }
        $entry = $page->entry();
        if (! $entry) {
            return;
        }
        $first = ! $entry->published();
        if ($first || $page->fresh) {
            $entry->setAttribute('published', true);
            if ($page->fresh) {
                $entry->set('content', $this->asText($sections));
            }
            $entry->save();
            rescue(fn () => Booklet::revisions()->snapshot($entry->revisionKey(), $entry, $first ? 'published' : 'edited', (string) request()->user()?->id, 'Published on the page'), null, false);
        }
        ActivityLog::record(($first ? 'published' : 'published changes to').$note, 'publishing', 'entry', (string) $entry->id(), $entry->title());
    }

    /** Off the site: a page or post back to a draft; the blog back to The cover's layout. What was built is kept. */
    public function takeOff(PageBuild $page): void
    {
        $page->put(['published' => null, 'published_at' => null, 'scheduled' => null]);
        $entry = $page->entry();
        if ($entry && $entry->published()) {
            $entry->setAttribute('published', false)->save();
            rescue(fn () => Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'unpublished', (string) request()->user()?->id), null, false);
            ActivityLog::record('unpublished', 'publishing', 'entry', (string) $entry->id(), $entry->title());
        }
    }

    /**
     * Scheduled pages (and changes) whose time has come go live: every five
     * minutes from Booklet's scheduler, and whenever a page is opened.
     */
    public function settle(): int
    {
        if (! app()->runningInConsole() && request()->attributes->get('booklet.builder.settled')) {
            return 0; // once a request is plenty
        }
        request()->attributes->set('booklet.builder.settled', true);
        $done = 0;
        $due = rescue(fn () => PageBuild::query()->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->get(), collect(), false);
        foreach ($due as $page) {
            if (! $page->isBlog() && ! $page->entry()) {
                $page->put(['scheduled' => null]); // its page was deleted

                continue;
            }
            $this->goLive($page, (array) ($page->scheduled['sections'] ?? []), $page->scheduled_at ?? now(), true);
            $done++;
        }

        return $done;
    }

    /** When the next scheduled page goes live (for pages kept for readers). */
    public function nextScheduled(): ?Carbon
    {
        $at = rescue(fn () => PageBuild::query()->whereNotNull('scheduled_at')->min('scheduled_at'), null, false);

        return $at ? Carbon::parse($at) : null;
    }

    /** A page's words as plain text: what Booklet shows should it be drawn without its sections. */
    public function asText(array $sections): string
    {
        $out = [];
        $walk = function (array $fields, array $values) use (&$walk, &$out): void {
            foreach ($fields as $name => $field) {
                $value = $values[$name] ?? null;
                $type = $field['type'] ?? '';
                if (in_array($type, ['text', 'words'], true) && is_string($value) && trim($value) !== '') {
                    $out[] = ($name === 'title' ? '## ' : '').trim($value);
                } elseif ($type === 'link' && is_array($value) && trim((string) ($value['label'] ?? '')) !== '' && trim((string) ($value['url'] ?? '')) !== '') {
                    $out[] = '['.trim((string) $value['label']).']('.trim((string) $value['url']).')';
                } elseif ($type === 'items' && is_array($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $walk((array) ($field['fields'] ?? []), $item);
                        }
                    }
                }
            }
        };
        foreach ($sections as $section) {
            $walk((array) ($this->builder->type($section['type'])['fields'] ?? []), (array) $section['fields']);
        }

        return implode("\n\n", $out);
    }

    /** [[builder: about]]: a built page's published sections, in a post or another page. */
    public function slip(string $address): string
    {
        $this->settle();
        $entry = app(Site::class)->findByUri('/'.Builder::address($address));
        $page = $entry ? PageBuild::forEntry($entry) : null;
        if (! $page || $page->published === null) {
            return '';
        }

        return view('builder.sections', ['sections' => $this->builder->clean($page->published), 'types' => $this->builder->types(), 'live' => false, 'embedded' => true])->render();
    }

    // Doing things in the editor ------------------------------------------------------------

    /** The editor's state as the page sent it, made safe. */
    public function stateFrom(array $in): array
    {
        return [
            'section' => is_string($in['section'] ?? null) && preg_match('/^[a-z0-9]{4,16}$/', $in['section']) ? $in['section'] : null,
            'tab' => in_array($in['tab'] ?? null, ['section', 'outline', 'page'], true) ? $in['tab'] : 'section',
            'adding' => (bool) ($in['adding'] ?? false),
            'picking' => is_array($in['picking'] ?? null) && is_string($in['picking']['id'] ?? null) && is_string($in['picking']['field'] ?? null)
                ? ['id' => $in['picking']['id'], 'field' => $in['picking']['field']] : null,
        ];
    }

    public function can(string $action): bool
    {
        return isset($this->actions()[$action]);
    }

    /**
     * Do something: given the editor's state, what was pressed and the page.
     * Answers with words to show, or ['form' => the new state, 'stamp' => words,
     * 'moved' => its new address, 'gone' => somewhere to go instead].
     */
    public function act(PageBuild $page, string $action, array $state, mixed $payload): mixed
    {
        return ($this->actions()[$action])($state, $payload, $page);
    }

    protected function actions(): array
    {
        $builder = $this->builder;
        $isPicture = fn (string $path): bool => $this->isPicture($path);
        $categories = fn (): array => $this->categories();
        $typesFor = fn (PageBuild $page): array => $this->typesFor($page);
        $sectionsOf = fn (PageBuild $page, bool $draft = true): array => $this->sectionsOf($page, $draft);
        $change = fn (PageBuild $page, callable $edit, ?string $mark = null): array => $this->change($page, $edit, $mark);
        $locate = fn (array $sections, mixed $id): array => $this->locate($sections, $id);
        $onSection = fn (PageBuild $page, mixed $id, callable $edit, ?string $mark = null): ?string => $this->onSection($page, $id, $edit, $mark);

        return [
            // The page -------------------------------------------------------------------------
            'publish' => function (array $form, $payload, PageBuild $page) {
                $first = ! $this->onSite($page);
                $this->goLive($page, $this->sectionsOf($page), now());
                if ($page->isBlog()) {
                    return $first ? 'Published. Readers see the blog arranged like this now.' : 'Published. Readers see the new arrangement now.';
                }
                $address = $this->addressOf($page);

                return $first ? ['stamp' => 'It’s on the site at '.$address.'.', 'moved' => $address] : 'Published. Readers see the new version now.';
            },
            // Draft, scheduled (for a date and time) or published.
            'status' => function (array $form, $payload, PageBuild $page) {
                if (! is_array($payload)) {
                    return null;
                }
                $to = (string) ($payload['status'] ?? '');
                if ($to === 'published') {
                    return $this->act($page, 'publish', $form, null);
                }
                if ($to === 'draft') {
                    $page->put(['scheduled' => null]);
                    if (! $this->onSite($page)) {
                        return $page->isBlog() ? 'Readers see the blog as The cover has it.' : 'It’s a draft: nobody else sees it.';
                    }

                    return $this->act($page, 'unpublish', $form, null);
                }
                if ($to !== 'scheduled') {
                    return null;
                }
                try {
                    $at = Carbon::parse((string) ($payload['at'] ?? ''), config('app.timezone'));
                } catch (\Throwable) {
                    $at = null;
                }
                if (! $at || ($payload['at'] ?? '') === '') {
                    throw ValidationException::withMessages(['form.at' => 'Choose a day and a time.']);
                }
                if ($at->isPast()) {
                    throw ValidationException::withMessages(['form.at' => 'Choose a time that hasn’t been yet.']);
                }
                if ($at->gt(now()->addYears(2))) {
                    throw ValidationException::withMessages(['form.at' => 'That’s a long way off. Choose a time within two years.']);
                }
                // What goes live is the page as it is now; later changes wait (the Page tab offers to add them).
                $page->put(['scheduled' => ['at' => $at->toIso8601String(), 'sections' => $this->sectionsOf($page)]]);

                return ! $this->onSite($page)
                    ? ($page->isBlog() ? 'The arrangement goes live on ' : 'It goes live on ').$this->when($at).'.'
                    : 'Your changes go live on '.$this->when($at).'. Until then readers see what’s on the site now.';
            },
            'reschedule' => function (array $form, $payload, PageBuild $page) {
                $at = $page->scheduled['at'] ?? null;
                if (! is_string($at)) {
                    return null;
                }
                $page->put(['scheduled' => ['at' => $at, 'sections' => $this->sectionsOf($page)]]);

                return 'Your latest changes are in it too. It still goes live on '.$this->when($at).'.';
            },
            'unschedule' => function (array $form, $payload, PageBuild $page) {
                $page->put(['scheduled' => null]);

                return 'Not scheduled any more.';
            },
            'discard' => function (array $form, $payload, PageBuild $page) {
                if ($page->published === null) {
                    return null;
                }
                $this->change($page, fn () => $this->sectionsOf($page, false));

                return 'Back to what’s on the site. (Undo brings your changes back.)';
            },
            'unpublish' => function (array $form, $payload, PageBuild $page) {
                $this->takeOff($page);
                if ($page->isBlog()) {
                    return 'The blog follows The cover’s layout again. (Your arrangement is kept as a draft.)';
                }

                // Its address answers nobody now: on, at its preview.
                return ['stamp' => 'Taken off the site. Only people who can edit it see it now.', 'moved' => route('preview', $page->target)];
            },
            // Drawn as Booklet draws it again: its own words (what was built here is let go).
            'stop' => function (array $form, $payload, PageBuild $page) {
                $entry = $page->entry();
                if (! $entry) {
                    return null;
                }
                $address = $this->addressOf($page);
                $page->delete();
                ActivityLog::record('stopped building', 'edits', 'entry', (string) $entry->id(), $entry->title());

                return ['stamp' => 'Drawn as before, with its own words.', 'gone' => $entry->published() ? url($address) : route('preview', (string) $entry->id())];
            },
            'details' => function (array $form, $payload, PageBuild $page) {
                $entry = $page->entry();
                if (! $entry || ! is_array($payload)) {
                    return null;
                }
                $data = validator($payload, ['name' => 'required|string|max:120', 'address' => 'required|string|max:160', 'description' => 'nullable|string|max:300'],
                    [], ['name' => 'name', 'description' => 'description'])->validate();
                $slug = \Illuminate\Support\Str::slug($data['address']);
                if ($slug === '') {
                    throw ValidationException::withMessages(['form.address' => 'Give it an address, like offer or our-story.']);
                }
                $old = $this->addressOf($page);
                $entry->set('title', trim($data['name']));
                trim((string) ($data['description'] ?? '')) !== '' ? $entry->set('meta_description', trim((string) $data['description'])) : $entry->remove('meta_description');
                $entry->setAttribute('slug', $slug)->save(); // Booklet keeps addresses one of a kind (offer-2…)
                rescue(fn () => Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'edited', (string) request()->user()?->id), null, false);
                $new = $this->addressOf($page);
                $moved = $new !== $old && $entry->published() && $old !== '/';
                if ($moved) {
                    // Links to the old address keep working.
                    rescue(fn () => \App\Support\Redirects::moved($old, $new), null, false);
                }
                $words = $entry->slug() !== $slug ? 'Saved. That address was taken, so it’s '.$new.'.' : ($moved ? 'Saved. '.$old.' now sends readers to '.$new.'.' : 'Saved.');

                return ['stamp' => $words, 'moved' => $new !== $old && $entry->published() ? $new : null];
            },
            'home' => function (array $form, $on, PageBuild $page) {
                $entry = $page->entry();
                if (! request()->user()?->isAdmin()) {
                    return 'Only an admin can choose the home page.';
                }
                if (! $entry || $entry->collection()?->dated() || (! $on && ! $this->isHome($entry))) {
                    return null;
                }
                Booklet::globals('site')?->set('homepage', $on ? $entry->id() : 'posts')->save();
                app()->forgetInstance(Site::class);
                if (! $on) {
                    return ['stamp' => 'Your latest posts are the home page again.', 'moved' => $entry->published() ? (string) $entry->uri() : null];
                }

                return $entry->published() ? ['stamp' => 'It’s the home page now.', 'moved' => '/'] : 'It becomes the home page when it’s on the site.';
            },

            // Choosing -------------------------------------------------------------------------
            'select' => fn (array $form, $id) => ['form' => array_merge($form, ['section' => is_string($id) ? $id : null, 'picking' => null, 'adding' => false, 'tab' => 'section'])],
            'tab' => fn (array $form, $tab) => ['form' => array_merge($form, ['tab' => in_array($tab, ['section', 'outline', 'page'], true) ? $tab : 'section', 'picking' => null, 'adding' => false])],
            'adding' => fn (array $form, $after) => ['form' => array_merge($form, ['adding' => $after !== false && $after !== null, 'section' => is_string($after) ? $after : ($form['section'] ?? null), 'picking' => null])],

            // Sections -------------------------------------------------------------------------
            'add' => function (array $form, $type, PageBuild $page) use ($builder, $change, $locate, $typesFor, $sectionsOf) {
                if (! is_string($type) || ! isset($typesFor($page)[$type]) || ! empty($builder->type($type)['required'])) {
                    return null;
                }
                if (count($sectionsOf($page)) >= Builder::MAX_SECTIONS) {
                    return 'That’s as many sections as a page can hold.';
                }
                if (! empty($builder->type($type)['once']) && collect($sectionsOf($page))->contains('type', $type)) {
                    return 'There’s one on the page already.';
                }
                $new = $builder->make($type);
                $change($page, function (array $sections) use ($new, $form, $locate) {
                    [$at] = $locate($sections, $form['section'] ?? null);
                    array_splice($sections, $at === null ? count($sections) : $at + 1, 0, [$new]);

                    return $sections;
                });

                return ['form' => array_merge($form, ['section' => $new['id'], 'adding' => false, 'picking' => null, 'tab' => 'section'])];
            },
            'move' => function (array $form, $payload, PageBuild $page) use ($change, $locate) {
                if (! is_array($payload)) {
                    return null;
                }
                $change($page, function (array $sections) use ($payload, $locate) {
                    [$from, $s] = $locate($sections, $payload['id'] ?? null);
                    if ($from === null) {
                        return $sections;
                    }
                    $to = isset($payload['to']) ? (int) $payload['to'] : $from + (int) ($payload['step'] ?? 0);
                    $to = max(0, min(count($sections) - 1, $to));
                    array_splice($sections, $from, 1);
                    array_splice($sections, $to, 0, [$s]);

                    return $sections;
                });

                return null;
            },
            'copy' => function (array $form, $id, PageBuild $page) use ($builder, $change, $locate, $sectionsOf) {
                if (count($sectionsOf($page)) >= Builder::MAX_SECTIONS) {
                    return null;
                }
                [, $s] = $locate($sectionsOf($page), $id);
                if ($s && ! empty($builder->type($s['type'])['required'])) {
                    return 'The posts show once on the blog page.';
                }
                if ($s && ! empty($builder->type($s['type'])['once'])) {
                    return 'It shows once on the page.';
                }
                $made = null;
                $change($page, function (array $sections) use ($id, $builder, $locate, &$made) {
                    [$i, $s] = $locate($sections, $id);
                    if ($i !== null) {
                        $made = $builder->copy($s);
                        array_splice($sections, $i + 1, 0, [$made]);
                    }

                    return $sections;
                });

                return $made ? ['form' => array_merge($form, ['section' => $made['id']])] : null;
            },
            'remove' => function (array $form, $id, PageBuild $page) use ($builder, $change, $locate, $sectionsOf) {
                [, $s] = $locate($sectionsOf($page), $id);
                if ($s && ! empty($builder->type($s['type'])['required'])) {
                    return 'The posts are what the blog page is for: they can move, but not go.';
                }
                $next = null;
                $change($page, function (array $sections) use ($id, $locate, &$next) {
                    [$i] = $locate($sections, $id);
                    if ($i !== null) {
                        array_splice($sections, $i, 1);
                        $next = $sections[min($i, count($sections) - 1)]['id'] ?? null;
                    }

                    return $sections;
                });

                return ['form' => array_merge($form, ['section' => $next]), 'stamp' => 'Taken out. (Undo puts it back.)'];
            },

            // Words typed on the page (or in a box in the drawer), and every other field.
            'set' => function (array $form, $payload, PageBuild $page) use ($builder, $onSection, $isPicture, $categories) {
                if (! is_array($payload) || ! is_string($payload['field'] ?? null)) {
                    return null;
                }
                $field = $payload['field'];
                $choices = str_ends_with($field, 'category') ? $categories() : [];

                return $onSection($page, $payload['id'] ?? null,
                    fn (array $s) => $builder->set($s, $field, $payload['value'] ?? '', $isPicture, $choices),
                    // A run of typing into the same words is one Undo step.
                    'set:'.($payload['id'] ?? '').':'.$field);
            },
            'look' => function (array $form, $payload, PageBuild $page) use ($builder, $onSection, $isPicture) {
                if (! is_array($payload) || ! is_string($payload['key'] ?? null)) {
                    return null;
                }

                return $onSection($page, $payload['id'] ?? null, fn (array $s) => $builder->setLook($s, $payload['key'], $payload['value'] ?? '', $isPicture));
            },
            'item' => function (array $form, $payload, PageBuild $page) use ($builder, $onSection) {
                if (! is_array($payload) || ! in_array($payload['do'] ?? '', ['add', 'move', 'remove'], true)) {
                    return null;
                }

                return $onSection($page, $payload['id'] ?? null,
                    fn (array $s) => $builder->items($s, (string) ($payload['field'] ?? ''), $payload['do'], (int) ($payload['at'] ?? -1), (int) ($payload['step'] ?? 0)));
            },

            // Choosing pictures from Media.
            'pick' => function (array $form, $payload) {
                if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['field'] ?? null)) {
                    return null;
                }

                return ['form' => array_merge($form, ['section' => $payload['id'], 'tab' => 'section', 'adding' => false,
                    'picking' => ['id' => $payload['id'], 'field' => $payload['field']]])];
            },
            'unpick' => fn (array $form) => ['form' => array_merge($form, ['picking' => null])],
            'choose' => function (array $form, $path, PageBuild $page) use ($builder, $onSection, $isPicture) {
                $picking = $form['picking'] ?? null;
                if (! is_array($picking) || ! is_string($path)) {
                    return null;
                }
                $many = false;
                $why = $onSection($page, $picking['id'] ?? null, function (array $s) use ($builder, $picking, $path, $isPicture, &$many) {
                    if ($picking['field'] === 'look.picture') {
                        return $builder->setLook($s, 'picture', $path, $isPicture);
                    }
                    $many = ($builder->field($s['type'], (string) $picking['field'])['kind'] ?? '') === 'pictures';
                    if ($many) {
                        // Several: press to add or take out.
                        $list = (array) data_get($s['fields'], $picking['field'], []);
                        $path = in_array($path, $list, true) ? array_values(array_diff($list, [$path])) : [...$list, $path];
                    }

                    return $builder->set($s, (string) $picking['field'], $path, $isPicture);
                });
                if ($why) {
                    return $why;
                }

                return $many ? null : ['form' => array_merge($form, ['picking' => null])];
            },

            'undo' => function (array $form, $payload, PageBuild $page) use ($builder, $locate) {
                $history = (array) $page->history;
                if ($history === []) {
                    return 'Nothing to undo.';
                }
                $back = $builder->clean(array_pop($history));
                $page->put(['draft' => $back, 'history' => $history, 'history_mark' => []]);
                [$i] = $locate($back, $form['section'] ?? null);

                return ['form' => array_merge($form, ['section' => $i === null ? ($back[0]['id'] ?? null) : $form['section'], 'picking' => null])];
            },
        ];
    }

    // Drawing -------------------------------------------------------------------------------

    /**
     * How a page or post is drawn for this request: [build, draft?, editor?]
     * when it's built, or null for Booklet's own drawing. Readers get what's
     * published; people who can build it get the draft while it's being built
     * for the first time, and ?preview=1 (the editor's Phone view) the draft
     * without the editor.
     */
    public function drawing(Entry $entry, Request $request): ?array
    {
        if (! $this->buildable($entry)) {
            return null;
        }
        $this->settle();
        $page = PageBuild::forEntry($entry);
        if (! $page) {
            return null;
        }
        // (Not in plain files or proof copies: nobody builds there.)
        $builder = ! $request->attributes->get('booklet_static') && ! $request->attributes->has('booklet_proof') && $this->canBuild($request->user(), $entry);
        if ($builder && $request->boolean('preview')) {
            return [$page, true, false];
        }
        if ($page->published !== null && $entry->published()) {
            return [$page, false, $builder];
        }

        return $builder ? [$page, true, true] : null;
    }

    /** The blog page, arranged: a response, or null for Booklet's own blog (as The cover draws it). */
    public function blog(Request $request): mixed
    {
        if (! $this->blogBuildable()) {
            return null;
        }
        $this->settle();
        $static = (bool) $request->attributes->get('booklet_static');
        $page = PageBuild::blog();
        $arranger = ! $static && $this->canBuild($request->user(), null);
        $preview = $arranger && $request->boolean('preview') && $page; // the editor's Phone view: the draft
        if ($preview) {
            $sections = $this->sectionsOf($page);
        } elseif ($page && $page->published !== null) {
            $sections = $this->sectionsOf($page, false);
        } elseif ($arranger) {
            $sections = $this->blogFromCover(); // what readers see (The cover's blog), with the editor to start arranging
        } else {
            return null;
        }

        return view('builder.blog', $this->blogContext($request, $sections) + [
            'sections' => $sections, 'types' => $this->builder->types(), 'draft' => (bool) $preview, 'editable' => $arranger && ! $preview,
        ]);
    }
}
