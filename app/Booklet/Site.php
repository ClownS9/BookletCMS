<?php

namespace App\Booklet;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Term;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\MenuOutline;
use App\Booklet\Support\PageTree;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Helpers for the public site: settings, menus, what's visible, pictures,
 * tags and Markdown. One instance per request (bound as a singleton).
 */
class Site
{
    protected ?array $settings = null;

    protected ?Collection $published = null;

    // Settings -------------------------------------------------------------

    public function setting(string $key, mixed $default = null): mixed
    {
        $this->settings ??= Booklet::globals('site')?->data() ?? [];

        return $this->settings[$key] ?? $default;
    }

    public function name(): string
    {
        return (string) $this->setting('site_name', config('app.name'));
    }

    public function tagline(): ?string
    {
        return $this->setting('tagline');
    }

    public function accent(): string
    {
        // From the cover (Settings › The cover), the unsaved one on its preview included.
        return \App\Support\Look::current()['accent'];
    }

    public function logoUrl(): ?string
    {
        $logo = $this->setting('logo');

        return $logo ? \App\Support\Disk::of('public')->url($logo) : null;
    }

    public function perPage(): int
    {
        return max(1, min(100, (int) $this->setting('per_page', 10)));
    }

    public function commentsOpen(): bool
    {
        return (bool) $this->setting('comments', true);
    }

    public function holdComments(): bool
    {
        return (bool) $this->setting('moderation', true);
    }

    // What the public can see ---------------------------------------------

    /**
     * Published entries only: drafts and anything scheduled for later stay hidden.
     *
     * @return Collection<int, Entry>
     */
    public function published(): Collection
    {
        return $this->published ??= Booklet::entries()->all()
            ->filter(fn (Entry $e) => $e->status() === 'published' && $e->site() === config('booklet.default_site'))
            ->values();
    }

    /**
     * @return Collection<int, Entry>
     */
    public function posts(?string $taxonomy = null, ?string $term = null): Collection
    {
        return $this->published()
            ->filter(fn (Entry $e) => $e->collection()?->dated())
            // One of each: translations are reached from their original, not listed beside it.
            ->reject(fn (Entry $e) => $this->isTranslation($e))
            ->when($taxonomy && $term, fn ($c) => $c->filter(
                fn (Entry $e) => in_array($term, (array) $e->get($taxonomy, []), true)
            ))
            ->sortByDesc(fn (Entry $e) => $e->attribute('date'))
            ->values();
    }

    // Languages -------------------------------------------------------------

    /** The site's own language (Settings), as a short code like "en". */
    public function language(): string
    {
        return strtolower(substr((string) $this->setting('language', 'en'), 0, 2)) ?: 'en';
    }

    public function isTranslation(Entry $entry): bool
    {
        return (string) $entry->get('translation_of', '') !== '';
    }

    /** The language an entry is written in. */
    public function languageOf(Entry $entry): string
    {
        return (string) ($entry->get('lang') ?: $this->language());
    }

    /**
     * Every published version of this entry (the original and its
     * translations), for the language switcher and the hreflang links.
     *
     * @return Collection<int, array{code: string, name: string, url: string, current: bool, original: bool}>
     */
    public function languages(Entry $entry): Collection
    {
        $rootId = $this->isTranslation($entry) ? (string) $entry->get('translation_of') : (string) $entry->id();

        $versions = $this->published()
            ->filter(fn (Entry $e) => (string) $e->id() === $rootId || (string) $e->get('translation_of') === $rootId);

        if ($versions->count() < 2) {
            return collect();
        }

        return $versions
            ->map(fn (Entry $e) => [
                'code' => $code = $this->languageOf($e),
                'name' => \App\Stacy\Writer::LANGUAGES[$code][1] ?? (\App\Livewire\Admin\Settings::LANGUAGES[$code] ?? strtoupper($code)),
                'url' => $this->absolute($this->url($e)),
                'current' => $e->id() === $entry->id(),
                'original' => (string) $e->id() === $rootId,
            ])
            ->unique('code')
            ->sortBy(fn ($v) => [$v['original'] ? 0 : 1, $v['name']])
            ->values();
    }

    public function findByUri(string $uri): ?Entry
    {
        $uri = '/'.trim($uri, '/');

        return $this->published()->first(fn (Entry $e) => $e->uri() === $uri);
    }

    public function findTermByUri(string $uri): ?Term
    {
        $uri = '/'.trim($uri, '/');

        return Booklet::terms()->all()->first(fn (Term $t) => $t->attribute('uri') === $uri);
    }

    public function homepage(): ?Entry
    {
        $choice = (string) $this->setting('homepage', 'posts');

        if ($choice === '' || $choice === 'posts') {
            return null;
        }

        return $this->published()->first(fn (Entry $e) => $e->id() === $choice);
    }

    /**
     * The address of the blog index: where the first dated collection lives.
     */
    /** The blog's path on the site ("/blog"), whatever address the site is reached at. */
    public function blogPath(): string
    {
        $route = (string) Booklet::collections()->all()->first(fn ($c) => $c->dated())?->route();

        return '/'.trim(Str::before($route, '{') ?: '/blog', '/');
    }

    public function blogUrl(): string
    {
        $route = (string) Booklet::collections()->all()->first(fn ($c) => $c->dated())?->route();

        return url(Str::before($route, '{') ?: '/blog');
    }

    public function url(Entry $entry): string
    {
        $home = $this->homepage();

        return $home && $home->id() === $entry->id() ? url('/') : url($entry->uri() ?? '/');
    }

    /**
     * The pages above this one in the tree, top first, for "Home / About / Team".
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function breadcrumbs(Entry $entry): array
    {
        $tree = Booklet::trees()->collection($entry->collectionHandle(), $entry->site());
        if (! $tree) {
            return [];
        }

        $pages = new \App\Booklet\Support\PageTree($tree);
        $trail = [];
        $id = $pages->parentOf($entry->id());

        while ($id && count($trail) < 10) {
            $parent = $this->published()->first(fn (Entry $e) => $e->id() === $id);
            if ($parent && $this->homepage()?->id() !== $parent->id()) {
                array_unshift($trail, ['title' => $parent->title(), 'url' => $this->url($parent)]);
            }
            $id = $pages->parentOf($id);
        }

        return $trail;
    }

    /**
     * A published page called "contact", for "Get in touch" links.
     */
    public function contactPage(): ?Entry
    {
        return $this->published()->first(fn (Entry $e) => $e->slug() === 'contact' && $e->collection()?->structured());
    }

    /**
     * A link on this site, using the address from Settings when one is set.
     */
    public function absolute(string $url): string
    {
        $base = rtrim((string) $this->setting('site_url', ''), '/');

        return $base !== '' ? $base.Str::after($url, rtrim(url('/'), '/')) : $url;
    }

    // Menus ----------------------------------------------------------------

    /**
     * A navigation's items, resolved to labels and links. Unpublished
     * entries are skipped.
     *
     * @return array<int, array{label: string, url: string, active: bool, children: array}>
     */
    public function menu(string $handle): array
    {
        // A seasonal version (a Raya menu, say) stands in for it between its dates.
        $source = rescue(fn () => \App\Booklet\Support\Menus::activeSeason($handle), null, false) ?? $handle;
        $tree = $this->menuPreview($handle) ?? Booklet::trees()->navigation($source)?->tree();

        return $tree ? $this->resolveBranches($tree) : [];
    }

    /** Where the menu editor keeps someone's unsaved menus for the live preview. */
    public static function menuPreviewKey(int|string $userId): string
    {
        return 'booklet.menu_preview.'.$userId;
    }

    /**
     * Unsaved menus, shown only to the person arranging them, and only on a
     * page opened from the editor's preview (?menu_preview=1).
     */
    protected function menuPreview(string $handle): ?array
    {
        $user = request()->user();
        if (! request()->query->has('menu_preview') || ! $user instanceof User || ! $user->arranges()) {
            return null;
        }

        $trees = Cache::get(static::menuPreviewKey($user->getAuthIdentifier()));

        return is_array($trees) && is_array($trees[$handle] ?? null) ? $trees[$handle] : null;
    }

    protected function resolveBranches(array $branches, int $depth = 0): array
    {
        $items = [];

        foreach ($branches as $branch) {
            $entry = null;
            if (isset($branch['entry'])) {
                $entry = $this->published()->first(fn (Entry $e) => $e->id() === $branch['entry']);
                if (! $entry) {
                    continue;
                }
                $url = $this->url($entry);
                $label = $branch['title'] ?? $entry->title();
            } elseif (isset($branch['url'])) {
                $url = Str::startsWith($branch['url'], ['http://', 'https://']) ? $branch['url'] : url($branch['url']);
                $label = $branch['title'] ?? $branch['url'];
            } else {
                continue;
            }

            $children = $depth < MenuOutline::MAX_DEPTH ? $this->resolveBranches((array) ($branch['children'] ?? []), $depth + 1) : [];

            // "Follow the page tree": its sub-pages fill in below whatever was placed by hand.
            if ($entry && ! empty($branch['follow']) && $depth < MenuOutline::MAX_DEPTH) {
                $placed = array_column($children, 'url');
                foreach ($this->subPages($entry, $depth + 1) as $sub) {
                    if (! in_array($sub['url'], $placed, true)) {
                        $children[] = $sub;
                    }
                }
            }

            $items[] = $this->menuItem($label, $url, $children) + [
                'mega' => $depth === 0 && ! empty($branch['mega']) ? $this->megaPanel($branch) : null,
            ];
        }

        return $items;
    }

    /**
     * A page's published sub-pages (and theirs, down to the menu's last level),
     * as menu items, in the order of the page tree.
     */
    public function subPages(Entry $entry, int $depth = 1): array
    {
        $collection = $entry->collectionHandle();
        $this->pageTrees[$collection] ??= collect(PageTree::for($collection)->flatten())->groupBy('parent')->map(fn ($rows) => $rows->pluck('id')->all())->all();

        $items = [];
        foreach ($this->pageTrees[$collection][$entry->id()] ?? [] as $id) {
            $child = $this->published()->first(fn (Entry $e) => $e->id() === $id);
            if ($child) {
                $items[] = $this->menuItem(
                    $child->title(),
                    $this->url($child),
                    $depth < MenuOutline::MAX_DEPTH ? $this->subPages($child, $depth + 1) : [],
                    true,
                );
            }
        }

        return $items;
    }

    /**
     * What a wide panel (mega menu) shows besides the links: a few words, a
     * picture, and the newest posts, each only if it was asked for.
     */
    protected function megaPanel(array $branch): array
    {
        $words = trim((string) ($branch['blurb'] ?? ''));
        $picture = ! empty($branch['picture']) ? rescue(fn () => \App\Support\Blocks::picture($branch['picture'], 'media', 'card'), null, false) : null;

        $latest = empty($branch['latest']) ? [] : $this->posts()->take(3)->map(fn (Entry $post) => [
            'label' => $post->title(),
            'url' => $this->url($post),
            'date' => $post->date()?->isoFormat('D MMM'),
        ])->values()->all();

        return ['words' => $words, 'picture' => $picture, 'latest' => $latest];
    }

    /** @var array<string, array<string, list<string>>> collection => parent id => child ids */
    protected array $pageTrees = [];

    protected function menuItem(string $label, string $url, array $children, bool $followed = false): array
    {
        $current = '/'.trim(request()->path(), '/');
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
        // A link to another website is never "the page you're on" (its empty path isn't our home page).
        $host = parse_url($url, PHP_URL_HOST);
        $elsewhere = is_string($host) && $host !== '' && strcasecmp($host, (string) parse_url(url('/'), PHP_URL_HOST)) !== 0;
        $active = ! $elsewhere && ($path === $current || ($path !== '/' && str_starts_with($current, $path.'/')));

        return [
            'label' => $label,
            'url' => $url,
            'active' => $active,
            // Somewhere under it is the page being read (so its drop-down tab is marked too).
            'within' => $active || collect($children)->contains(fn ($c) => $c['active'] || $c['within']),
            'followed' => $followed,
            'children' => $children,
        ];
    }

    // Pictures, people, tags ----------------------------------------------

    /**
     * The URL of the first picture in an assets field (stored as paths in the
     * "media" container by default).
     */
    public function image(Entry $entry, string $field = 'cover', string $container = 'media'): ?string
    {
        $path = ((array) $entry->get($field, []))[0] ?? null;

        if (! $path) {
            return null;
        }

        $disk = Booklet::assetContainers()->find($container)?->attribute('disk') ?? 'public';

        return \App\Support\Disk::of($disk)->url($path);
    }

    /**
     * An entry's picture in a named size ("card", "hero", "square"…, set in
     * Media › Sizes), cut around its focal point: url, srcset, alt, width,
     * height. Null when the field has no picture. In a theme, for example:
     * $pic = $site->crop($entry, 'hero'), then an img with $pic['url'] and $pic['srcset'].
     */
    public function crop(Entry $entry, string $size, string $field = 'cover', string $container = 'media'): ?array
    {
        return \App\Support\Crops::forEntry($entry, $size, $field, $container);
    }

    /**
     * The picture shown when a link to the entry is shared: its share image or
     * its cover, in the "share" size when there is one. Null when it has neither.
     */
    public function shareImage(Entry $entry): ?string
    {
        foreach (['share_image', 'cover'] as $field) {
            if ($this->image($entry, $field)) {
                return isset(\App\Support\Crops::sizes()['share'])
                    ? \App\Support\Crops::forEntry($entry, 'share', $field)['url'] ?? $this->image($entry, $field)
                    : $this->image($entry, $field);
            }
        }

        return null;
    }

    /** Smaller copies of an entry's picture for srcset ('' when there are none to offer). */
    public function imageSrcset(Entry $entry, string $field = 'cover', string $container = 'media'): string
    {
        return \App\Support\Pictures::srcset(((array) $entry->get($field, []))[0] ?? null, $container);
    }

    /** Where to keep the picture in view when it's cropped (its focal point), for object-position. */
    public function imageFocus(Entry $entry, string $field = 'cover', string $container = 'media'): string
    {
        return \App\Support\Focus::forPath(((array) $entry->get($field, []))[0] ?? null, $container);
    }

    public function imageAlt(Entry $entry, string $field = 'cover', string $container = 'media'): string
    {
        $path = ((array) $entry->get($field, []))[0] ?? null;

        return $path ? (string) Booklet::assets()->find(['container' => $container, 'path' => $path])?->get('alt', '') : '';
    }

    public function author(Entry $entry): ?User
    {
        $id = $entry->get('author');

        return $id ? User::find($id) : null;
    }

    /**
     * @return Collection<int, Term>
     */
    public function terms(Entry $entry, string $taxonomy): Collection
    {
        return collect((array) $entry->get($taxonomy, []))
            ->map(fn ($slug) => Booklet::terms()->findBySlug($taxonomy, (string) $slug))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, Term>
     */
    public function tagCloud(string $taxonomy = 'tags', int $limit = 12): Collection
    {
        $counts = $this->posts()->flatMap(fn (Entry $e) => (array) $e->get($taxonomy, []))->countBy();

        return Booklet::terms()->whereTaxonomy($taxonomy)
            ->filter(fn (Term $t) => ($counts[$t->slug()] ?? 0) > 0)
            ->sortByDesc(fn (Term $t) => $counts[$t->slug()] ?? 0)
            ->take($limit)
            ->values();
    }

    // Words ----------------------------------------------------------------

    /**
     * Markdown to HTML, with raw HTML stripped and unsafe links removed.
     */
    public function markdown(?string $text): HtmlString
    {
        // Pictures in the text get sized copies and load lazily; [[slips]] become what their insert makes.
        return new HtmlString(app(\App\Inserts\InsertManager::class)->renderSlips(\App\Support\Pictures::enhanceHtml(Str::markdown((string) $text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]))));
    }

    /** The entry's linked entries and rows, drawn with their names as headings. */
    public function extras(Entry $entry): HtmlString
    {
        return \App\Support\Blocks::extrasFor($entry);
    }

    /**
     * The published entries a linked-entries field points at, in the order chosen
     * (for themes: @foreach ($site->linked($entry, 'related') as $other)).
     *
     * @return Collection<int, Entry>
     */
    public function linked(Entry $entry, string $field): Collection
    {
        return \App\Support\Links::entries($entry->get($field));
    }

    /** A rows field's rows, every column there (for themes). */
    public function rows(Entry $entry, string $field): array
    {
        $config = \App\Support\Blocks::blueprintFields($entry)[$field] ?? [];

        return \App\Support\Rows::forEditor($entry->get($field), \App\Support\Rows::columns($config));
    }

    /** The entry's blocks fields (cut-outs: quotes, pictures, buttons…), drawn in order. */
    public function blocks(Entry $entry): HtmlString
    {
        return \App\Support\Blocks::forEntry($entry);
    }

    /** What search engines and shared links say about an entry: its own search description, or its first lines. */
    public function description(Entry $entry): string
    {
        $own = trim((string) $entry->get('meta_description', ''));

        return $own !== '' ? Str::limit($own, 300, '') : $this->excerpt($entry, 160);
    }

    public function excerpt(Entry $entry, int $length = 180): string
    {
        $text = (string) ($entry->get('excerpt') ?? $entry->get('meta_description') ?? '');

        if ($text === '') {
            $text = strip_tags((string) Str::markdown(\App\Inserts\InsertManager::stripSlips((string) $entry->get('content', '')), ['html_input' => 'strip']));
        }
        if (trim($text) === '') {
            $text = \App\Support\Blocks::textOf($entry); // a page built only from cut-outs
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text)), $length);
    }

    public function readingTime(Entry $entry): int
    {
        return max(1, (int) round(str_word_count(strip_tags((string) $entry->get('content', '')).' '.\App\Support\Blocks::textOf($entry)) / 220));
    }
}
