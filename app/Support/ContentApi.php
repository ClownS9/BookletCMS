<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Term;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Booklet\Support\PageTree;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The read-only content API (Settings › Content API): published posts and
 * pages as JSON, for an app or another website. Off until an admin turns it
 * on; then each request needs a key made there, unless it's opened to
 * everyone. Drafts, scheduled posts and anything private never leave.
 */
class ContentApi
{
    /** Requests a minute, per key (or per address when open to everyone). */
    public const PER_MINUTE = 120;

    /** The most entries one page of a list can hold. */
    public const MAX_LIMIT = 100;

    /** Fields shown on every entry already (or kept to Booklet itself), so not repeated under "fields". */
    protected const OWN = ['title', 'content', 'excerpt', 'cover', 'author', 'slug', 'date', 'published', 'lang', 'translation_of', 'meta_title', 'meta_description', 'share_image'];

    protected static function settingsFile(): string
    {
        return StaticSite::privatePath('content-api.json');
    }

    /** @return array{on: bool, open: bool, origins: list<string>} */
    public static function settings(): array
    {
        $saved = rescue(fn () => json_decode((string) @file_get_contents(self::settingsFile()), true), [], false);
        $saved = is_array($saved) ? $saved : [];

        return [
            'on' => (bool) ($saved['on'] ?? false),
            'open' => (bool) ($saved['open'] ?? false),
            'origins' => array_values(array_filter(array_map('strval', (array) ($saved['origins'] ?? [])))),
        ];
    }

    public static function saveSettings(array $changes): void
    {
        $settings = array_merge(self::settings(), array_intersect_key($changes, array_flip(['on', 'open', 'origins'])));
        File::ensureDirectoryExists(dirname(self::settingsFile()));
        file_put_contents(self::settingsFile(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** "https://app.example, https://other.example" → a clean list of origins ("*" for anywhere). */
    public static function parseOrigins(string $text): array
    {
        $origins = [];
        foreach (preg_split('/[\s,]+/', trim($text)) ?: [] as $part) {
            if ($part === '*') {
                return ['*'];
            }
            if (preg_match('#^(https?://[^/\s?]+)#i', $part, $m)) {
                $origins[] = strtolower($m[1]);
            }
        }

        return array_values(array_unique($origins));
    }

    /** Whether a browser on this origin may read the answers. */
    public static function allowsOrigin(?string $origin): bool
    {
        if (! $origin) {
            return false;
        }
        $allowed = self::settings()['origins'];

        return in_array('*', $allowed, true) || in_array(strtolower(rtrim($origin, '/')), $allowed, true);
    }

    /** The API's own address, for the examples in Settings. */
    public static function base(): string
    {
        return url('/api/v1');
    }

    // What an entry looks like as JSON -------------------------------------------------

    public static function entry(Entry $entry, bool $full = true): array
    {
        $site = app(Site::class);
        $author = $site->author($entry);
        $collection = $entry->collection();

        $out = [
            'id' => (string) $entry->id(),
            'collection' => $entry->collectionHandle(),
            'title' => $entry->title(),
            'slug' => (string) $entry->slug(),
            'url' => $site->absolute($site->url($entry)),
            'uri' => $entry->uri(),
            'date' => $collection?->dated() ? $entry->date()?->timezone(config('app.timezone'))->toIso8601String() : null,
            'language' => $site->languageOf($entry),
            'author' => $author ? ['name' => $author->name, 'byline' => (string) ($author->byline ?? '') ?: null] : null,
            'excerpt' => $site->excerpt($entry),
            'description' => $site->description($entry),
            'reading_minutes' => $site->readingTime($entry),
            'cover' => self::picture($entry, 'cover'),
            'terms' => self::termsOf($entry),
        ];

        if ($collection?->structured()) {
            $row = collect(PageTree::for($entry->collectionHandle())->flatten())->firstWhere('id', (string) $entry->id());
            $out['parent'] = $row['parent'] ?? null;
        }

        if ($site->isTranslation($entry)) {
            $out['translation_of'] = (string) $entry->get('translation_of');
        }

        // Members-only: who it's for, and its first lines (above); the rest stays on the site.
        if (Members::locked($entry)) {
            $rule = Members::rule($entry);
            $out['members_only'] = ['open_to' => $rule['who'], 'groups' => $rule['groups']];
            $full = false;
        }

        if ($full) {
            $markdown = (string) $entry->get('content', '');
            $out['content'] = [
                'markdown' => $markdown,
                'html' => self::absoluteLinks((string) $site->markdown($markdown)),
            ];
            $blocks = trim((string) $site->blocks($entry).(string) $site->extras($entry));
            if ($blocks !== '') {
                $out['content']['blocks_html'] = self::absoluteLinks($blocks);
            }
            $out['share_image'] = ($share = $site->shareImage($entry)) ? self::full($share) : null;
            $out['fields'] = self::fields($entry);
        }

        return $out;
    }

    /** A picture field's first picture: where it is, what it shows, smaller copies. */
    public static function picture(Entry $entry, string $field): ?array
    {
        $site = app(Site::class);
        $url = $site->image($entry, $field);
        if (! $url) {
            return null;
        }

        return [
            'url' => self::full($url),
            'alt' => $site->imageAlt($entry, $field),
            'srcset' => self::absoluteLinks($site->imageSrcset($entry, $field)) ?: null,
        ];
    }

    /** taxonomy => [{slug, title, url}] */
    protected static function termsOf(Entry $entry): array
    {
        $site = app(Site::class);
        $out = [];
        foreach ((array) $entry->collection()?->taxonomies() as $taxonomy) {
            $out[$taxonomy] = $site->terms($entry, (string) $taxonomy)->map(fn (Term $t) => self::term($t))->all();
        }

        return $out;
    }

    public static function term(Term $term, ?int $count = null): array
    {
        $uri = (string) $term->attribute('uri', '');

        return array_filter([
            'slug' => $term->slug(),
            'title' => $term->title(),
            'url' => $uri !== '' ? app(Site::class)->absolute(url($uri)) : null,
            'count' => $count,
        ], fn ($v) => $v !== null);
    }

    /** The entry's other fields, from its blueprint: pictures as addresses, linked entries as small cards. */
    protected static function fields(Entry $entry): array
    {
        $site = app(Site::class);
        $out = [];
        foreach (Blocks::blueprintFields($entry) as $handle => $config) {
            if (in_array($handle, self::OWN, true) || ($config['type'] ?? '') === 'terms' || ($config['type'] ?? '') === 'blocks') {
                continue;
            }
            $value = $entry->get($handle);
            $out[$handle] = match ($config['type'] ?? 'text') {
                'assets' => array_values(array_filter(array_map(function ($path) use ($config) {
                    if (! is_string($path) || $path === '') {
                        return null;
                    }
                    $container = (string) ($config['container'] ?? 'media');
                    $disk = Booklet::assetContainers()->find($container)?->attribute('disk') ?? 'public';

                    return [
                        'url' => self::full(Disk::of($disk)->url($path)),
                        'alt' => (string) Booklet::assets()->find(['container' => $container, 'path' => $path])?->get('alt', ''),
                    ];
                }, (array) $value))),
                'markdown' => $value === null ? null : ['markdown' => (string) $value, 'html' => self::absoluteLinks((string) $site->markdown((string) $value))],
                'entries' => Links::entries($value)->map(fn (Entry $e) => ['id' => (string) $e->id(), 'title' => $e->title(), 'url' => $site->absolute($site->url($e))])->values()->all(),
                default => $value,
            };
        }

        return $out;
    }

    /** Links and pictures in HTML written as full addresses, so they work on another website. */
    public static function absoluteLinks(string $html): string
    {
        $root = rtrim(app(Site::class)->absolute(url('/')), '/');

        return (string) preg_replace_callback('~(\s(?:href|src)=")(/[^/"][^"]*|/)"|(^|,\s*)(/[^/\s,][^\s,]*)(\s+\d+w)~', function ($m) use ($root) {
            if ($m[1] !== '') {
                return $m[1].$root.$m[2].'"';
            }

            return $m[3].$root.$m[4].$m[5];
        }, $html);
    }

    /** A full address on this site (the one set in Settings when there is one). */
    public static function full(string $url): string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = url($url);
        }

        return app(Site::class)->absolute($url);
    }

    /** Does an entry match a few words of search? */
    public static function matches(Entry $entry, string $words): bool
    {
        $text = Str::lower($entry->title().' '.$entry->get('excerpt', '').' '.$entry->get('content', ''));
        foreach (preg_split('/\s+/', Str::lower(trim($words))) ?: [] as $word) {
            if ($word !== '' && ! str_contains($text, $word)) {
                return false;
            }
        }

        return true;
    }
}
