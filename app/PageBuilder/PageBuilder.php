<?php

namespace App\PageBuilder;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Models\ActivityLog;
use App\Models\PageBuild;
use App\Models\SharedSection;
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
        // Remembered for this request (a page of cards asks about the same pictures).
        Fields::$pictures ??= function (string $path): ?array {
            $key = 'booklet.builder.picture::'.$path;
            if (! request()->attributes->has($key)) {
                $asset = rescue(fn () => Booklet::assets()->find(['container' => 'media', 'path' => $path]), null, false);
                request()->attributes->set($key, $asset && $asset->isImage() ? \App\Support\Blocks::picture($path) : null);
            }

            return request()->attributes->get($key);
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
        $sections = $this->resolve($builder->clean($draft ? $page->draft : $page->published), $draft);
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
        $post = (bool) $entry->collection()?->dated();
        $sections = $this->fromEntry($entry);
        // Nothing of its own to start from (only its title): a page starts from a layout.
        $empty = trim((string) $entry->get('content')) === '' && count($sections) <= 1;
        if ($empty && ! $post) {
            $sections = $this->builder->starter('landing');
        }
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
        // A new change: what Undo took back can't come again.
        $page->put(['draft' => $after, 'history' => $history, 'history_mark' => ['mark' => $mark, 'at' => now()->timestamp], 'redo' => null]);
        $this->keepShared($after);
        request()->attributes->remove('booklet.builder.uses');

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
        $this->keepShared($sections, true); // shared sections on it go live wherever they're used
        $page->put(['published' => $sections, 'published_at' => $at, 'scheduled' => null]);
        // Kept, to look at again and to put back (the oldest go after a while).
        $page->versions()->create(['sections' => $sections, 'published_at' => $at, 'user_id' => $scheduled ? null : request()->user()?->id, 'scheduled' => $scheduled]);
        $page->versions()->skip(\App\Models\PageBuildVersion::KEEP)->take(1000)->get(['id'])->each->delete();
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

    /**
     * What readers read on a built page or post (its published sections, as
     * text), or null when Booklet draws it: for search, excerpts and reading
     * time. Looked up once a request.
     */
    public function readerText(Entry $entry): ?string
    {
        $all = request()->attributes->get('booklet.builder.texts');
        if (! is_array($all)) {
            $all = rescue(fn () => PageBuild::query()->whereNotNull('published')->where('target', '!=', PageBuild::BLOG)->get(['target', 'published'])
                ->mapWithKeys(fn (PageBuild $b) => [$b->target => $b->published])->all(), [], false);
            request()->attributes->set('booklet.builder.texts', $all);
        }
        $sections = $all[(string) $entry->id()] ?? null;
        if (! is_array($sections) || ! $entry->published() || ! $this->buildable($entry)) {
            return null;
        }

        return Fields::plain(str_replace('## ', '', $this->asText($this->resolve($this->builder->clean($sections), false))));
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

        return view('builder.sections', ['sections' => $this->sectionsOf($page, false), 'types' => $this->builder->types(), 'live' => false, 'embedded' => true])->render();
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
            // An earlier version (from the history) back in the draft, to publish again.
            'restore' => function (array $form, $id, PageBuild $page) {
                $version = is_numeric($id) ? $page->versions()->whereKey((int) $id)->first() : null;
                if (! $version) {
                    return 'That version isn’t kept any more.';
                }
                $this->change($page, fn () => $this->builder->clean($version->sections));

                return 'Back as it was on '.$this->when($version->published_at).'. Publish to put it on the site. (Undo takes it back.)';
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
                // One of the shared sections ("shared:7"): the same one, here too.
                $shared = is_string($type) && preg_match('/^shared:(\d+)$/', $type, $m) ? ($this->sharedAll()[(int) $m[1]] ?? null) : null;
                if ($shared) {
                    if (! isset($typesFor($page)[$shared->type]) || ! $this->shareable($shared->type)) {
                        return null;
                    }
                    $new = ['id' => $builder->make($shared->type)['id'], 'type' => $shared->type, 'fields' => $shared->draft['fields'] ?? [], 'look' => $shared->draft['look'] ?? [], 'shared' => $shared->id];
                    $change($page, function (array $sections) use ($new, $form, $locate) {
                        [$at] = $locate($sections, $form['section'] ?? null);
                        array_splice($sections, $at === null ? count($sections) : $at + 1, 0, [$new]);

                        return $sections;
                    });

                    return ['form' => array_merge($form, ['section' => $new['id'], 'adding' => false, 'picking' => null, 'tab' => 'section'])];
                }
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
                        unset($made['shared']); // a copy is this page's own
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

                $why = $onSection($page, $payload['id'] ?? null,
                    fn (array $s) => $builder->set($s, $field, $payload['value'] ?? '', $isPicture, $choices),
                    // A run of typing into the same words is one Undo step.
                    'set:'.($payload['id'] ?? '').':'.$field);
                if ($why !== null) {
                    // Can't go there: said under the field, with what was typed kept to fix.
                    throw ValidationException::withMessages(['form.field:'.$field => $why]);
                }

                return null;
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
                $redo = array_slice([...(array) $page->redo, $this->sectionsOf($page)], -30);
                $page->put(['draft' => $back, 'history' => $history, 'history_mark' => [], 'redo' => $redo]);
                $this->keepShared($back);
                [$i] = $locate($back, $form['section'] ?? null);

                return ['form' => array_merge($form, ['section' => $i === null ? ($back[0]['id'] ?? null) : $form['section'], 'picking' => null])];
            },
            // What Undo took back, again.
            'redo' => function (array $form, $payload, PageBuild $page) use ($builder, $locate) {
                $redo = (array) $page->redo;
                if ($redo === []) {
                    return 'Nothing to redo.';
                }
                $again = $builder->clean(array_pop($redo));
                $history = array_slice([...(array) $page->history, $this->sectionsOf($page)], -30);
                $page->put(['draft' => $again, 'history' => $history, 'history_mark' => [], 'redo' => $redo]);
                $this->keepShared($again);
                [$i] = $locate($again, $form['section'] ?? null);

                return ['form' => array_merge($form, ['section' => $i === null ? ($again[0]['id'] ?? null) : $form['section'], 'picking' => null])];
            },

            // A copy of this section on another page (at the end of its draft).
            'send' => function (array $form, $payload, PageBuild $page) use ($locate, $sectionsOf) {
                if (! is_array($payload)) {
                    return null;
                }
                [, $s] = $locate($sectionsOf($page), $payload['id'] ?? null);
                if (! $s) {
                    return 'That section isn’t on the page any more.';
                }
                $to = $this->sendTargets($page, $s['type'])[(string) ($payload['to'] ?? '')] ?? null;
                if (! $to) {
                    throw ValidationException::withMessages(['form.send' => 'Choose a page to send it to.']);
                }
                $there = $to['page'];
                $kind = (array) $this->builder->type($s['type']);
                $already = $sectionsOf($there);
                if (count($already) >= Builder::MAX_SECTIONS) {
                    throw ValidationException::withMessages(['form.send' => '“'.$to['name'].'” has as many sections as a page can hold.']);
                }
                if (! empty($kind['once']) && collect($already)->contains('type', $s['type'])) {
                    throw ValidationException::withMessages(['form.send' => '“'.$to['name'].'” has one already, and it shows once on a page.']);
                }
                $made = $this->builder->copy($s);
                if (isset($made['shared']) && ! isset($this->sharedAll()[$made['shared']])) {
                    unset($made['shared']);
                }
                $this->change($there, fn (array $sections) => [...$sections, $made]);
                $there->isBlog()
                    ? ActivityLog::record('sent a section to', 'edits', 'builder', PageBuild::BLOG, 'The blog')
                    : ActivityLog::record('sent a section to', 'edits', 'entry', $there->target, $to['name']);

                return ['stamp' => 'A copy is at the end of “'.$to['name'].'” (a draft change there, until it’s published).', 'open' => $this->editUrl($there)];
            },

            // Stacy writes the words for a section, as asked (one Undo step).
            'stacy' => function (array $form, $payload, PageBuild $page) {
                $user = request()->user();
                if (! is_array($payload) || ! $user || ! \App\Stacy\Writer::available($user)) {
                    return null;
                }
                [, $s] = $this->locate($this->sectionsOf($page), $payload['id'] ?? null);
                if (! $s) {
                    return null;
                }
                $kind = (array) $this->builder->type($s['type']);
                $fields = $this->wordFields((array) ($kind['fields'] ?? []));
                if ($fields === []) {
                    return 'There are no words in this one to write.';
                }
                try {
                    $words = \App\Stacy\Writer::section($user, (string) $kind['name'], (string) ($kind['about'] ?? ''), $fields,
                        $this->wordsOf($fields, (array) $s['fields']), (string) ($payload['ask'] ?? ''), $page->isBlog() ? 'The blog' : (string) $page->entry()?->title(),
                        Fields::plain($this->asText(array_values(array_filter($this->sectionsOf($page), fn ($o) => $o['id'] !== $s['id'])))));
                } catch (\App\Stacy\StacyException $e) {
                    throw ValidationException::withMessages(['form.stacy' => $e->getMessage()]);
                }
                $why = $this->onSection($page, $s['id'], fn (array $section) => $this->fillWords($section, $fields, $words));
                if ($why !== null) {
                    return $why;
                }

                return 'Stacy wrote it. Read it over and change anything that isn’t right (anything in [brackets] is yours to fill in). Undo takes it back.';
            },

            // Pictures ------------------------------------------------------------------------
            // A picture's description (for people who can't see it), kept in Media: right everywhere it's used.
            'alt' => function (array $form, $payload, PageBuild $page) {
                $asset = $this->mediaPicture(is_array($payload) ? ($payload['path'] ?? null) : null);
                if (! $asset) {
                    return null;
                }
                $alt = trim(mb_substr(strip_tags((string) ($payload['alt'] ?? '')), 0, 500));
                $alt === '' ? $asset->remove('alt') : $asset->set('alt', $alt);
                Booklet::assets()->save($asset);
                $this->forgetPicture($asset->path());

                return $alt === '' ? 'Description taken out.' : 'Description saved, for everywhere it’s used.';
            },
            // The spot that matters in it (a face, the cake): kept in view wherever it's cropped.
            'focus' => function (array $form, $payload, PageBuild $page) {
                $asset = $this->mediaPicture(is_array($payload) ? ($payload['path'] ?? null) : null);
                if (! $asset || ! is_numeric($payload['x'] ?? null) || ! is_numeric($payload['y'] ?? null)) {
                    return null;
                }
                $asset->set('focus', \App\Support\Focus::store((float) $payload['x'], (float) $payload['y']));
                Booklet::assets()->save($asset);
                $this->forgetPicture($asset->path());

                return 'That spot stays in view, everywhere it’s used.';
            },

            // Layouts -------------------------------------------------------------------------
            // Start again from a layout: one Booklet comes with ("starter:shop"), or a saved one ("saved:3").
            'layout' => function (array $form, $key, PageBuild $page) {
                $sections = $this->layoutSections($page, (string) $key);
                if ($sections === null) {
                    return 'That layout isn’t there any more.';
                }
                $this->change($page, fn () => $sections);

                return ['stamp' => 'Started again from that layout. (Undo takes it back.)', 'form' => array_merge($form, ['section' => $sections[0]['id'] ?? null])];
            },
            // This page, saved as a layout to start others from.
            'save-layout' => function (array $form, $payload, PageBuild $page) {
                $name = $this->builder->line(is_array($payload) ? ($payload['name'] ?? '') : '', 80);
                if ($name === '') {
                    throw ValidationException::withMessages(['form.layout' => 'Give it a name, to choose it by.']);
                }
                if (\App\Models\PageLayout::count() >= \App\Models\PageLayout::MAX) {
                    throw ValidationException::withMessages(['form.layout' => 'There are '.\App\Models\PageLayout::MAX.' layouts already. Forget one you don’t use first.']);
                }
                \App\Models\PageLayout::create(['name' => $name, 'sections' => $this->sectionsOf($page), 'created_by' => request()->user()?->id]);

                return 'Saved as the layout “'.$name.'”. Start any page from it, under Start from a layout.';
            },
            'forget-layout' => function (array $form, $id, PageBuild $page) {
                $layout = is_numeric($id) ? \App\Models\PageLayout::find((int) $id) : null;
                if (! $layout) {
                    return null;
                }
                $layout->delete();

                return 'The layout “'.$layout->name.'” is gone. (Pages started from it stay as they are.)';
            },

            // Sharing the draft (a proof copy) --------------------------------------------------
            // A private link to the draft, for someone without an account (they can leave notes).
            'proof' => function (array $form, $payload, PageBuild $page) {
                $entry = $page->entry();
                if (! $entry || ! is_array($payload)) {
                    return null;
                }
                $label = $this->builder->line($payload['label'] ?? '', 80);
                $days = (int) ($payload['days'] ?? 7);
                if (\App\Models\Proof::where('entry_id', $entry->id())->open()->count() >= \App\Models\Proof::MAX_OPEN) {
                    throw ValidationException::withMessages(['form.proof' => 'There are '.\App\Models\Proof::MAX_OPEN.' open links already. Stop one you don’t need first.']);
                }
                [$proof] = \App\Models\Proof::issue((string) $entry->id(), request()->user(), $days, $label, (bool) ($payload['notes'] ?? false));
                ActivityLog::record('made a proof copy of', 'edits', 'entry', (string) $entry->id(), $entry->title(), $proof->label ? ['for' => $proof->label] : []);

                return ['stamp' => 'Link made. Copy it and send it'.($proof->label ? ' to '.$proof->label : '').'.', 'copy' => $proof->url()];
            },
            'proof-stop' => function (array $form, $id, PageBuild $page) {
                $entry = $page->entry();
                $proof = is_numeric($id) && $entry ? \App\Models\Proof::where('entry_id', $page->target)->find((int) $id) : null;
                if (! $proof) {
                    return null;
                }
                $proof->update(['revoked_at' => now()]);
                ActivityLog::record('stopped sharing a proof copy of', 'edits', 'entry', $page->target, $entry->title());

                return 'That link works no more.';
            },
            // A note from a proof copy: seen to (or not after all).
            'proof-note' => function (array $form, $id, PageBuild $page) {
                $note = is_numeric($id) ? \App\Models\ProofNote::where('entry_id', $page->target)->find((int) $id) : null;
                if (! $note) {
                    return null;
                }
                $note->update($note->resolved_at ? ['resolved_at' => null, 'resolved_by' => null] : ['resolved_at' => now(), 'resolved_by' => request()->user()?->id]);

                return null;
            },

            // Shared sections ------------------------------------------------------------------
            // This section, used on other pages too (from Add a section › Shared).
            'share' => function (array $form, $payload, PageBuild $page) use ($change, $locate, $sectionsOf) {
                if (! is_array($payload)) {
                    return null;
                }
                [, $s] = $locate($sectionsOf($page), $payload['id'] ?? null);
                if (! $s || isset($s['shared']) || ! $this->shareable($s['type'])) {
                    return null;
                }
                $name = $this->builder->line($payload['name'] ?? '', 80);
                if ($name === '') {
                    throw ValidationException::withMessages(['form.share' => 'Give it a name, to find it by on other pages.']);
                }
                $shared = SharedSection::create(['name' => $name, 'type' => $s['type'], 'draft' => ['fields' => $s['fields'], 'look' => $s['look']]]);
                $this->forgetShared();
                $change($page, function (array $sections) use ($s, $shared, $locate) {
                    [$i] = $locate($sections, $s['id']);
                    if ($i !== null) {
                        $sections[$i]['shared'] = $shared->id;
                    }

                    return $sections;
                });

                return 'Shared as “'.$name.'”. Add it to other pages from Add a section › Shared.';
            },
            // This page's own again (as it is now); the shared one stays for the others.
            'unshare' => function (array $form, $id, PageBuild $page) use ($change) {
                $change($page, function (array $sections) use ($id) {
                    foreach ($sections as $i => $s) {
                        if ($s['id'] === $id) {
                            unset($sections[$i]['shared']);
                        }
                    }

                    return $sections;
                });

                return 'It’s this page’s own now: changes here stay here.';
            },
            // A shared section no page uses any more.
            'forget-shared' => function (array $form, $id, PageBuild $page) {
                $shared = is_numeric($id) ? SharedSection::find((int) $id) : null;
                if (! $shared) {
                    return null;
                }
                if ($this->usesOf($shared->id) > 0) {
                    return 'It’s still on a page. Take it off the pages first.';
                }
                $shared->delete();
                $this->forgetShared();

                return '“'.$shared->name.'” is gone.';
            },
        ];
    }

    // Stacy writing a section -----------------------------------------------------------------

    /** The fields of a section that hold words (and lists of them), for Stacy. */
    protected function wordFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $f) {
            if (in_array($f['type'] ?? '', ['text', 'words'], true)) {
                $out[$name] = $f;
            } elseif (($f['type'] ?? '') === 'items' && ($inner = $this->wordFields((array) ($f['fields'] ?? []))) !== []) {
                $out[$name] = ['fields' => $inner] + $f;
            }
        }

        return $out;
    }

    /** What's in those fields now. */
    protected function wordsOf(array $fields, array $values): array
    {
        $out = [];
        foreach ($fields as $name => $f) {
            $out[$name] = ($f['type'] ?? '') === 'items'
                ? array_map(fn ($item) => $this->wordsOf((array) $f['fields'], (array) $item), array_values(array_filter((array) ($values[$name] ?? []), 'is_array')))
                : (string) ($values[$name] ?? '');
        }

        return $out;
    }

    /** A section with Stacy's words put in (each checked as if typed; lists take the number of things she wrote). */
    protected function fillWords(array $section, array $fields, array $words): array
    {
        foreach ($fields as $name => $f) {
            if (! array_key_exists($name, $words)) {
                continue;
            }
            if (($f['type'] ?? '') === 'items') {
                $wanted = array_values(array_filter((array) $words[$name], 'is_array'));
                if ($wanted === []) {
                    continue;
                }
                $list = array_values(array_filter((array) ($section['fields'][$name] ?? []), 'is_array'));
                $kept = [];
                foreach (array_slice($wanted, 0, (int) ($f['max_items'] ?? 20)) as $i => $item) {
                    // The same thing as before (its picture, address…), or one like the last, with Stacy's words.
                    $kept[] = $list[$i] ?? ($list !== [] ? end($list) : []);
                }
                $section['fields'][$name] = $kept;
                foreach (array_slice($wanted, 0, count($kept)) as $i => $item) {
                    foreach ($f['fields'] as $sub => $spec) {
                        if (is_scalar($item[$sub] ?? null)) {
                            $section = rescue(fn () => $this->builder->set($section, $name.'.'.$i.'.'.$sub, (string) $item[$sub], fn ($p) => $this->isPicture($p)), $section, false);
                        }
                    }
                }
            } elseif (is_scalar($words[$name])) {
                $section = rescue(fn () => $this->builder->set($section, $name, (string) $words[$name], fn ($p) => $this->isPicture($p)), $section, false);
            }
        }

        return $section;
    }

    // Translating a built page --------------------------------------------------------------

    /**
     * Every piece of words a reader sees in a section, by where it is:
     * ['title' => …, 'items.0.question' => …, 'button.label' => …].
     *
     * @return array<string, string>
     */
    public function wordsIn(array $section): array
    {
        $out = [];
        $walk = function (array $fields, array $values, string $at) use (&$walk, &$out): void {
            foreach ($fields as $name => $f) {
                $type = $f['type'] ?? '';
                if (in_array($type, ['text', 'words'], true) && is_scalar($values[$name] ?? null) && trim((string) $values[$name]) !== '') {
                    $out[$at.$name] = trim((string) $values[$name]);
                } elseif ($type === 'link' && trim((string) ($values[$name]['label'] ?? '')) !== '') {
                    $out[$at.$name.'.label'] = trim((string) $values[$name]['label']);
                } elseif ($type === 'items') {
                    foreach (array_values(array_filter((array) ($values[$name] ?? []), 'is_array')) as $i => $item) {
                        $walk((array) ($f['fields'] ?? []), $item, $at.$name.'.'.$i.'.');
                    }
                }
            }
        };
        $walk((array) ($this->builder->type($section['type'])['fields'] ?? []), (array) $section['fields'], '');

        return $out;
    }

    /**
     * A built page's sections in another language, for its translation: the
     * same sections, pictures and looks, with Stacy's words. A shared section
     * becomes the translation's own (the shared one stays in its language).
     */
    public function translateSections(User $user, PageBuild $page, string $language, string $title = ''): array
    {
        $sections = array_map(function (array $s) {
            unset($s['shared']);

            return $s;
        }, $this->sectionsOf($page));
        $words = [];
        foreach ($sections as $i => $s) {
            foreach ($this->wordsIn($s) as $path => $text) {
                $words[$i.'|'.$path] = $text;
            }
        }
        foreach (\App\Stacy\Writer::translateWords($user, $words, \App\Stacy\Writer::languageName($language), $title) as $key => $text) {
            [$i, $path] = explode('|', $key, 2);
            $sections[(int) $i] = rescue(fn () => $this->builder->set($sections[(int) $i], $path, $text, fn ($p) => $this->isPicture($p)), $sections[(int) $i], false);
        }

        return $sections;
    }

    /** The translation of a built page is built too, in its language (on the site as soon as it's published, as the original is). */
    public function buildTranslation(PageBuild $original, Entry $copy, array $sections): PageBuild
    {
        return PageBuild::create([
            'target' => (string) $copy->id(), 'draft' => $sections, 'history' => [], 'fresh' => (bool) $original->fresh,
            'published' => $original->published !== null ? $sections : null, 'published_at' => $original->published !== null ? now() : null,
        ]);
    }

    // Pictures --------------------------------------------------------------------------------

    /** A picture in Media this person may change (descriptions and focus are Media's), or null. */
    protected function mediaPicture(mixed $path): ?\App\Booklet\Data\Asset
    {
        if (! is_string($path) || $path === '' || ! request()->user()?->canChangeMedia()) {
            return null;
        }
        $asset = rescue(fn () => Booklet::assets()->find(['container' => 'media', 'path' => $path]), null, false);

        return $asset && $asset->isImage() ? $asset : null;
    }

    /** What's remembered about a picture for this request, forgotten (it just changed). */
    protected function forgetPicture(string $path): void
    {
        request()->attributes->remove('booklet.builder.picture::'.$path);
        request()->attributes->remove('booklet.focus.media::'.$path);
        \App\Support\PageCache::flush(); // readers' pages show it too
    }

    // Layouts ---------------------------------------------------------------------------------

    /**
     * Layouts to start a page from: the ones Booklet comes with, and pages
     * saved as layouts. (Not for the blog page: its posts are what it's for.)
     *
     * @return array{starters: list<array{key: string, name: string, about: string}>, saved: list<array{key: string, id: int, name: string, about: string}>}|null
     */
    public function layouts(PageBuild $page): ?array
    {
        if ($page->isBlog()) {
            return null;
        }
        $types = $this->builder->types();
        $saved = rescue(fn () => \App\Models\PageLayout::query()->orderBy('name')->get(), collect(), false);

        return [
            'starters' => collect($this->builder->starters())->map(fn ($s, $key) => ['key' => 'starter:'.$key, 'name' => (string) $s['name'], 'about' => (string) $s['about']])->values()->all(),
            'saved' => $saved->map(fn (\App\Models\PageLayout $l) => [
                'key' => 'saved:'.$l->id, 'id' => $l->id, 'name' => $l->name,
                'about' => collect($l->sections)->map(fn ($s) => (string) ($types[$s['type'] ?? '']['name'] ?? ''))->filter()->take(6)->implode(', '),
            ])->values()->all(),
        ];
    }

    /** The sections to start again from a layout (new ones each time), or null. */
    public function layoutSections(PageBuild $page, string $key): ?array
    {
        if ($page->isBlog()) {
            return null;
        }
        if (preg_match('/^starter:([a-z-]+)$/', $key, $m) && isset($this->builder->starters()[$m[1]])) {
            $sections = $this->builder->starter($m[1]);
            $title = $page->entry()?->title();
            if ($title && ($sections[0]['type'] ?? '') === 'hero') {
                $sections[0]['fields']['title'] = $this->builder->line($title, 160);
            }

            return $sections;
        }
        $layout = preg_match('/^saved:(\d+)$/', $key, $m) ? \App\Models\PageLayout::find((int) $m[1]) : null;
        if (! $layout) {
            return null;
        }
        // Its sections, as new ones on this page (shared ones stay shared).
        return array_map(fn ($s) => $this->builder->copy($s), array_values(array_filter($this->builder->clean($layout->sections), fn ($s) => empty($this->builder->type($s['type'])['blog']))));
    }

    // Sharing the draft -----------------------------------------------------------------------

    /**
     * The open links to this page's draft, and the notes left on them that
     * aren't seen to yet (for the Page tab). The blog page has none: it
     * isn't one of the pages.
     *
     * @return array{links: list<array{id: int, label: string|null, closes: string, opens: int, notes: int, url: string|null}>, notes: list<array{id: int, name: string, quote: string|null, body: string, when: string}>, done: int}|null
     */
    public function proofing(PageBuild $page): ?array
    {
        if ($page->isBlog() || ! $page->entry()) {
            return null;
        }
        $links = \App\Models\Proof::withCount('notes')->where('entry_id', $page->target)->open()->latest()->get();
        $notes = \App\Models\ProofNote::where('entry_id', $page->target)->latest()->limit(100)->get();

        return [
            'links' => $links->map(fn (\App\Models\Proof $p) => [
                'id' => $p->id, 'label' => $p->label, 'closes' => $p->expires_at->timezone(config('app.timezone'))->format('j M'),
                'opens' => $p->opens, 'notes' => (int) $p->getAttribute('notes_count'), 'url' => $p->url(),
            ])->values()->all(),
            'notes' => $notes->whereNull('resolved_at')->take(20)->map(fn (\App\Models\ProofNote $n) => [
                'id' => $n->id, 'name' => $n->name, 'quote' => $n->quote, 'body' => $n->body, 'when' => $n->created_at->diffForHumans(),
            ])->values()->all(),
            'done' => $notes->whereNotNull('resolved_at')->count(),
        ];
    }

    // Checks before publishing -----------------------------------------------------------------

    /** Labels that are meant to be kept as they start (not example words). */
    protected const KEPT_AS_THEY_START = ['more', 'label', 'all', 'empty', 'about_title', 'recent_title', 'tags_title', 'days', 'time'];

    /**
     * Things to look at before publishing: example words still there,
     * buttons that go nowhere, pictures without a description, a video or
     * pictures not chosen, a form or emails that are switched off. Nothing
     * here stops anyone publishing.
     *
     * @return list<array{section: string|null, words: string}>
     */
    public function checks(PageBuild $page): array
    {
        $out = [];
        $sections = $this->sectionsOf($page);
        if ($sections === [] || ($page->isBlog() && count($sections) === 1)) {
            $out[] = ['section' => null, 'words' => 'Nothing on the page yet.'];
        }
        foreach ($sections as $s) {
            $kind = $this->builder->type($s['type']) ?? [];
            $name = (string) ($kind['name'] ?? $s['type']);
            $say = function (string $words) use (&$out, $s, $name): void {
                $out[] = ['section' => $s['id'], 'words' => $name.': '.$words];
            };
            $fields = (array) ($kind['fields'] ?? []);
            $start = (array) ($kind['start'] ?? []);
            $example = null;
            $nowhere = [];
            $undescribed = 0;
            $walk = function (array $specs, array $values, array $starts) use (&$walk, &$example, &$nowhere, &$undescribed): void {
                foreach ($specs as $key => $spec) {
                    $value = $values[$key] ?? null;
                    switch ($spec['type'] ?? '') {
                        case 'text':
                        case 'words':
                            $was = $starts[$key] ?? null;
                            if ($example === null && is_string($value) && trim($value) !== '' && $value === $was && ! in_array($key, self::KEPT_AS_THEY_START, true)) {
                                $example = $value;
                            }
                            break;
                        case 'link':
                            $label = trim((string) ($value['label'] ?? ''));
                            $url = trim((string) ($value['url'] ?? ''));
                            if ($label !== '' && ($url === '' || $url === '#')) {
                                $nowhere[] = $label;
                            }
                            break;
                        case 'picture':
                            $pic = is_string($value) && $value !== '' ? Fields::pictureFor($value) : null;
                            $undescribed += $pic && trim((string) $pic['alt']) === '' ? 1 : 0;
                            break;
                        case 'pictures':
                            foreach ((array) $value as $path) {
                                $pic = is_string($path) ? Fields::pictureFor($path) : null;
                                $undescribed += $pic && trim((string) $pic['alt']) === '' ? 1 : 0;
                            }
                            break;
                        case 'items':
                            foreach (array_values(array_filter((array) $value, 'is_array')) as $i => $item) {
                                $walk((array) ($spec['fields'] ?? []), $item, (array) (array_values(array_filter((array) ($starts[$key] ?? []), 'is_array'))[$i] ?? []));
                            }
                            break;
                    }
                }
            };
            $walk($fields, (array) $s['fields'], $start);

            if ($example !== null) {
                $say('still has its example words (“'.\Illuminate\Support\Str::limit(Fields::plain($example), 40, '…', true).'”).');
            }
            foreach (array_unique($nowhere) as $label) {
                $say('the button “'.\Illuminate\Support\Str::limit($label, 30, '…').'” doesn’t go anywhere yet.');
            }
            if ($undescribed > 0) {
                $say(($undescribed === 1 ? 'a picture has' : $undescribed.' pictures have').' no description, for people who can’t see them (select the picture to add one).');
            }
            if ($s['type'] === 'video' && trim((string) ($s['fields']['url'] ?? '')) === '') {
                $say('no video chosen yet.');
            }
            if (in_array($s['type'], ['gallery', 'logos'], true) && array_filter((array) ($s['fields']['pictures'] ?? []), 'is_string') === []) {
                $say('no pictures chosen yet.');
            }
            if ($s['type'] === 'hero' && ($s['fields']['layout'] ?? 'side') !== 'none' && trim((string) ($s['fields']['picture'] ?? '')) === '') {
                $say('no picture yet (or choose No picture, under Where the picture goes).');
            }
            if ($s['type'] === 'banner' && trim((string) ($s['fields']['picture'] ?? '')) === '') {
                $say('no picture yet.');
            }
            if ($s['type'] === 'contact' && ! rescue(fn () => \App\Support\Inbox::open(), true, false)) {
                $say('the contact form is switched off, so readers won’t see it (Settings › Email).');
            }
            if ($s['type'] === 'subscribe' && ! rescue(fn () => \App\Support\Newsletter::enabled(), true, false)) {
                $say('new posts by email aren’t switched on, so readers won’t see it (Settings › Email).');
            }
        }

        return $out;
    }

    // Shared sections ------------------------------------------------------------------------

    /** Every shared section, by id (looked up once a request). @return array<int, SharedSection> */
    /**
     * The other built pages a section of this kind could be sent to, that
     * whoever is building may build: target => ['name' => …, 'page' => PageBuild].
     *
     * @return array<string, array{name: string, page: PageBuild}>
     */
    public function sendTargets(PageBuild $page, string $type): array
    {
        $kind = (array) $this->builder->type($type);
        if ($kind === [] || ! empty($kind['required'])) {
            return [];
        }
        $user = request()->user();
        $out = [];
        foreach (PageBuild::query()->where('target', '!=', $page->target)->latest('updated_at')->take(200)->get() as $other) {
            if (! isset($this->typesFor($other)[$type]) || ! $this->canBuildThis($user, $other)) {
                continue;
            }
            $out[$other->target] = ['name' => $other->isBlog() ? 'The blog page' : (string) $other->entry()?->title(), 'page' => $other];
        }
        uasort($out, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    public function sharedAll(): array
    {
        $all = request()->attributes->get('booklet.builder.shared');
        if (! is_array($all)) {
            $all = rescue(fn () => SharedSection::query()->orderBy('name')->get()->keyBy('id')->all(), [], false);
            request()->attributes->set('booklet.builder.shared', $all);
        }

        return $all;
    }

    protected function forgetShared(): void
    {
        request()->attributes->remove('booklet.builder.shared');
        request()->attributes->remove('booklet.builder.uses');
    }

    /** Can this kind of section be shared? (Not the blog's own: they belong to the blog page.) */
    public function shareable(string $type): bool
    {
        $kind = $this->builder->type($type);

        return $kind !== null && empty($kind['blog']);
    }

    /**
     * Sections as drawn: a shared one wears its words and look from where
     * they're kept (the draft, or what's published). One never published
     * shows what this page had when it was published.
     */
    public function resolve(array $sections, bool $draft): array
    {
        $all = $this->sharedAll();
        foreach ($sections as $i => $s) {
            if (! isset($s['shared'])) {
                continue;
            }
            $shared = $all[$s['shared']] ?? null;
            if (! $shared || ! $this->builder->type($shared->type)) {
                unset($sections[$i]['shared']); // gone: this page's own copy stays
                continue;
            }
            $content = $draft ? $shared->draft : $shared->published;
            if ($content === null) {
                continue;
            }
            $sections[$i] = $this->builder->clean([['id' => $s['id'], 'type' => $shared->type, 'fields' => $content['fields'] ?? [], 'look' => $content['look'] ?? []]])[0]
                + ['shared' => $shared->id];
        }

        return array_values($sections);
    }

    /** The words and look of the shared sections among these, kept for every page that uses them (and, publishing, put live). */
    protected function keepShared(array $sections, bool $publish = false): void
    {
        $all = $this->sharedAll();
        foreach ($sections as $s) {
            $shared = isset($s['shared']) ? ($all[$s['shared']] ?? null) : null;
            if (! $shared) {
                continue;
            }
            $content = ['fields' => $s['fields'], 'look' => $s['look']];
            $changes = array_filter(['draft' => $shared->draft != $content ? $content : null, 'published' => $publish && $shared->published != $content ? $content : null]);
            if ($changes !== []) {
                $shared->fill($changes)->save();
            }
        }
    }

    /** How many pages (drafts or what's published) use a shared section. */
    public function usesOf(int $id): int
    {
        $uses = request()->attributes->get('booklet.builder.uses');
        if (! is_array($uses)) {
            $uses = [];
            foreach (rescue(fn () => PageBuild::query()->get(['id', 'draft', 'published']), collect(), false) as $build) {
                $here = [];
                foreach ([(array) $build->draft, (array) $build->published] as $list) {
                    foreach ($list as $s) {
                        if (is_array($s) && is_int($s['shared'] ?? null)) {
                            $here[$s['shared']] = true;
                        }
                    }
                }
                foreach (array_keys($here) as $sid) {
                    $uses[$sid] = ($uses[$sid] ?? 0) + 1;
                }
            }
            request()->attributes->set('booklet.builder.uses', $uses);
        }

        return (int) ($uses[$id] ?? 0);
    }

    /** The shared sections a page could add, for the list. @return list<array{id: int, name: string, kind: string, type: string, uses: int}> */
    public function sharedFor(PageBuild $page): array
    {
        $types = $this->typesFor($page);

        return array_values(array_map(fn (SharedSection $s) => [
            'id' => $s->id, 'name' => $s->name, 'type' => $s->type, 'kind' => (string) ($types[$s->type]['name'] ?? $s->type), 'uses' => $this->usesOf($s->id),
        ], array_filter($this->sharedAll(), fn (SharedSection $s) => isset($types[$s->type]) && $this->shareable($s->type))));
    }

    /**
     * What was published, newest first, for the Page tab: when, who, and
     * whether it's what's on the site now.
     *
     * @return list<array{id: int, when: string, who: string, current: bool, preview: string}>
     */
    public function history(PageBuild $page, int $take = 12): array
    {
        $live = $this->onSite($page) && $page->published !== null ? $this->sectionsOf($page, false) : null;
        $out = [];
        $current = false;
        $versions = $page->versions()->take($take)->get();
        $names = User::query()->whereIn('id', $versions->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        foreach ($versions as $version) {
            $now = ! $current && $live !== null && $this->builder->clean($version->sections) == $live;
            $current = $current || $now;
            $out[] = [
                'id' => $version->id,
                'when' => $this->when($version->published_at),
                'who' => $version->scheduled ? 'scheduled' : (string) ($names[$version->user_id] ?? 'someone'),
                'current' => $now,
                'preview' => route('builder.preview', $page->target).'?version='.$version->id,
            ];
        }

        return $out;
    }

    /** What to draw: what's published, the draft, or (the Phone view of the history) one kept version. */
    public function shown(PageBuild $page, bool $draft, Request $request): array
    {
        if ($draft && $request->boolean('preview') && $request->filled('version')) {
            return $this->version($page, $request->query('version')) ?? $this->sectionsOf($page);
        }

        return $this->sectionsOf($page, $draft);
    }

    /** The sections of one kept version of this page, for looking at (or null). */
    public function version(PageBuild $page, mixed $id): ?array
    {
        $version = is_numeric($id) ? $page->versions()->whereKey((int) $id)->first() : null;

        return $version ? $this->builder->clean($version->sections) : null;
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
        // A proof copy: the draft as it stands, for whoever has the link (with their notes, never the editor).
        if ($request->attributes->has('booklet_proof')) {
            return [$page, true, false];
        }
        // (Not in plain files: nobody builds there.)
        $builder = ! $request->attributes->get('booklet_static') && $this->canBuild($request->user(), $entry);
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
            $sections = $this->shown($page, true, $request);
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
