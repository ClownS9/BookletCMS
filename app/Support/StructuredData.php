<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Term;
use App\Booklet\Site;
use App\Models\Comment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Structured data (schema.org, as JSON-LD): what each public page is, in a
 * form search engines read. Posts are Articles with their author, dates
 * and picture; pages say where they sit; the site says its name, who
 * publishes it and how to search it. Inserts add their own (Events adds
 * an Event for each one coming up) with StructuredData::add().
 *
 * Everything for a page is gathered while it's drawn and written once in
 * its <head>, as one @graph.
 */
class StructuredData
{
    protected const KEY = 'booklet_structured_data';

    /** Add a thing to this page's structured data (a schema.org node, as an array). */
    public static function add(array $node): void
    {
        $nodes = (array) request()->attributes->get(self::KEY, []);
        $nodes[] = $node;
        request()->attributes->set(self::KEY, $nodes);
    }

    /** Start this page's again (when it's drawn a second time, without a broken theme). */
    public static function reset(): void
    {
        request()->attributes->remove(self::KEY);
    }

    /** The one <script> for the page's <head>. */
    public static function render(): HtmlString
    {
        $site = app(Site::class);
        $graph = array_values(array_filter([self::website($site), self::publisher($site), ...(array) request()->attributes->get(self::KEY, [])]));
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return new HtmlString('<script type="application/ld+json">'.$json.'</script>');
    }

    // The site ------------------------------------------------------------------------------

    public static function home(?Site $site = null): string
    {
        return rtrim(($site ?? app(Site::class))->absolute(url('/')), '/').'/';
    }

    public static function id(string $what): string
    {
        return self::home().'#'.$what;
    }

    protected static function website(Site $site): array
    {
        return array_filter([
            '@type' => 'WebSite',
            '@id' => self::id('website'),
            'url' => self::home($site),
            'name' => $site->name(),
            'description' => $site->tagline() ?: null,
            'inLanguage' => $site->language(),
            'publisher' => ['@id' => self::id('publisher')],
            // A search box for the site, right in the search results.
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $site->absolute(route('site.search')).'?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ]);
    }

    protected static function publisher(Site $site): array
    {
        $logo = $site->logoUrl();

        return array_filter([
            '@type' => 'Organization',
            '@id' => self::id('publisher'),
            'name' => $site->name(),
            'url' => self::home($site),
            'logo' => $logo ? ['@type' => 'ImageObject', 'url' => self::absolute($site, $logo)] : null,
        ]);
    }

    // Pages ---------------------------------------------------------------------------------

    /** A post: an article with its author, dates, picture, category and tags. */
    public static function post(Entry $entry): void
    {
        $site = app(Site::class);
        $url = $site->absolute($site->url($entry));
        $author = $site->author($entry);
        $category = $site->terms($entry, 'categories')->first();
        $words = str_word_count(strip_tags((string) $site->markdown((string) $entry->get('content', ''))));

        self::add(array_filter([
            '@type' => 'BlogPosting',
            '@id' => $url.'#article',
            'headline' => Str::limit($entry->title() ?: 'Untitled', 110, '…'),
            'description' => self::description($entry) ?: null,
            'image' => self::images($entry),
            'datePublished' => self::date($entry->date()),
            'dateModified' => self::date($entry->lastModified() ? Carbon::parse($entry->lastModified()) : $entry->date()),
            'author' => $author ? array_filter([
                '@type' => 'Person',
                'name' => $author->byline ?: $author->name,
                'description' => $author->bio ? Str::limit(strip_tags((string) $author->bio), 300) : null,
                'image' => $author->avatar ? self::absolute($site, \App\Support\Disk::of('public')->url($author->avatar)) : null,
            ]) : ['@id' => self::id('publisher')],
            'publisher' => ['@id' => self::id('publisher')],
            'mainEntityOfPage' => $url,
            'url' => $url,
            'isPartOf' => ['@id' => self::id('website')],
            'inLanguage' => $site->languageOf($entry),
            'articleSection' => $category?->title(),
            'keywords' => $site->terms($entry, 'tags')->map->title()->implode(', ') ?: null,
            'wordCount' => $words ?: null,
            'commentCount' => rescue(fn () => Comment::where('entry_id', $entry->id())->where('status', 'approved')->count(), 0, false) ?: null,
        ]));

        // Home › Blog › This post (unless the blog is the home page).
        $trail = [['name' => $site->name(), 'url' => self::home($site)]];
        if (rtrim($site->absolute($site->blogUrl()), '/').'/' !== self::home($site)) {
            $trail[] = ['name' => 'Blog', 'url' => $site->absolute($site->blogUrl())];
        }
        $trail[] = ['name' => $entry->title() ?: 'Untitled', 'url' => $url];
        self::breadcrumbs($trail);
    }

    /** A page: what kind of page it is, and where it sits among the others. */
    public static function page(Entry $entry): void
    {
        $site = app(Site::class);
        $url = $site->absolute($site->url($entry));
        $isHome = $site->homepage()?->id() === $entry->id();
        $type = match (true) {
            $isHome => 'WebPage',
            $site->contactPage()?->id() === $entry->id() => 'ContactPage',
            in_array($entry->slug(), ['about', 'about-us', 'about-me'], true) => 'AboutPage',
            default => 'WebPage',
        };

        self::add(array_filter([
            '@type' => $type,
            '@id' => $url.'#page',
            'url' => $url,
            'name' => $entry->get('meta_title') ?: $entry->title(),
            'description' => self::description($entry) ?: null,
            'primaryImageOfPage' => ($images = self::images($entry, drawn: false)) ? ['@type' => 'ImageObject', 'url' => $images[0]] : null,
            'isPartOf' => ['@id' => self::id('website')],
            'inLanguage' => $site->languageOf($entry),
            'dateModified' => self::date($entry->lastModified() ? Carbon::parse($entry->lastModified()) : null),
            'breadcrumb' => $isHome ? null : ['@id' => $url.'#breadcrumb'],
        ]));

        if (! $isHome) {
            self::breadcrumbs([
                ['name' => $site->name(), 'url' => self::home($site)],
                ...array_map(fn ($c) => ['name' => $c['title'], 'url' => $site->absolute($c['url'])], $site->breadcrumbs($entry)),
                ['name' => $entry->title() ?: 'Untitled', 'url' => $url],
            ], $url.'#breadcrumb');
        }
    }

    /**
     * The blog (or a category or tag): a list of posts.
     *
     * @param  Collection<int, Entry>  $posts  the ones on this page
     */
    public static function listing(string $name, Collection $posts, ?Term $term = null): void
    {
        $site = app(Site::class);
        $url = $site->absolute(url()->current());

        self::add(array_filter([
            '@type' => $term ? 'CollectionPage' : 'Blog',
            '@id' => $url.'#'.($term ? 'page' : 'blog'),
            'url' => $url,
            'name' => $name,
            'description' => $term ? ($term->get('description') ?: null) : ($site->tagline() ?: null),
            'isPartOf' => ['@id' => self::id('website')],
            'publisher' => ['@id' => self::id('publisher')],
            ($term ? 'hasPart' : 'blogPost') => $posts->take(20)->map(fn (Entry $e) => array_filter([
                '@type' => 'BlogPosting',
                '@id' => $site->absolute($site->url($e)).'#article',
                'headline' => Str::limit($e->title() ?: 'Untitled', 110, '…'),
                'url' => $site->absolute($site->url($e)),
                'datePublished' => self::date($e->date()),
            ]))->values()->all() ?: null,
        ]));
    }

    /** @param array<int, array{name: string, url: string}> $trail */
    public static function breadcrumbs(array $trail, ?string $id = null): void
    {
        if (count($trail) < 2) {
            return;
        }
        self::add([
            '@type' => 'BreadcrumbList',
            '@id' => $id ?? end($trail)['url'].'#breadcrumb',
            'itemListElement' => array_map(fn ($crumb, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => $crumb['url']], $trail, array_keys($trail)),
        ]);
    }

    // Small things ------------------------------------------------------------------------------

    public static function date(?\DateTimeInterface $date): ?string
    {
        return $date ? Carbon::instance($date)->toIso8601String() : null;
    }

    protected static function description(Entry $entry): string
    {
        return trim((string) ($entry->get('meta_description') ?: app(Site::class)->excerpt($entry, 200)));
    }

    /** The entry's pictures, full addresses: its share image and cover, or the card drawn for it. */
    protected static function images(Entry $entry, bool $drawn = true): array
    {
        $site = app(Site::class);
        $images = array_values(array_unique(array_filter([$site->image($entry, 'share_image'), $site->image($entry)])));
        if ($images === [] && $drawn) {
            $images = array_filter([rescue(fn () => ShareImage::urlFor($entry), null, false)]);
        }

        return array_values(array_map(fn ($i) => self::absolute($site, $i), $images));
    }

    protected static function absolute(Site $site, string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $site->absolute($url) : $site->absolute(url($url));
    }
}
