<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use Illuminate\Support\Collection;

/**
 * Linked entries: a field that points at other posts or pages (Statamic's
 * Entries field). Kept as a list of entry ids, in the order chosen:
 *
 *   related:
 *     - a3030b36-02c5-4934-b5e4-4a84fb8d1c6b
 *     - 9e1d…
 *
 * Its config can narrow where they come from ('collections: [posts]') and
 * how many ('max_items: 3'). The other way round, Links::to() finds every
 * entry that points at this one, so nobody tears out a page others link to
 * without knowing.
 */
class Links
{
    public const MAX = 50;

    /** Keys that hold an entry id but aren't a link someone chose. */
    protected const NOT_LINKS = ['translation_of', 'author', 'updated_by', 'id'];

    /** Does this look like an entry id? (So stray words are never taken for one.) */
    public static function looksLikeId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,63}$/', $id) === 1;
    }

    /**
     * Ids from the editor, kept only when the entry exists, is from an allowed
     * chapter and isn't this entry itself; no repeats, and no more than allowed.
     *
     * @return list<string>
     */
    public static function clean(mixed $ids, array $field = [], ?string $self = null): array
    {
        $allowed = self::collectionsOf($field);
        $max = (int) ($field['max_items'] ?? 0) ?: self::MAX;
        $out = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            if (! self::looksLikeId($id) || $id === $self || in_array($id, $out, true)) {
                continue;
            }
            $entry = Booklet::entries()->find($id);
            if (! $entry || ($allowed !== [] && ! in_array($entry->collectionHandle(), $allowed, true))) {
                continue;
            }
            $out[] = $id;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** @return list<string> the chapters a field links into (empty: any) */
    public static function collectionsOf(array $field): array
    {
        return array_values(array_filter((array) ($field['collections'] ?? []), 'is_string'));
    }

    /**
     * The linked entries, in order. For readers, only the ones that are out.
     *
     * @return Collection<int, Entry>
     */
    public static function entries(mixed $ids, bool $publishedOnly = true): Collection
    {
        return collect(is_array($ids) ? $ids : [])
            ->filter(fn ($id) => self::looksLikeId($id))
            ->unique()
            ->map(fn ($id) => Booklet::entries()->find($id))
            ->filter(fn (?Entry $e) => $e !== null && (! $publishedOnly || $e->status() === 'published'))
            ->values();
    }

    /**
     * Every entry that links to this one (in a linked-entries field, a cut-out,
     * a row…), newest change first.
     *
     * @return Collection<int, Entry>
     */
    public static function to(Entry $entry): Collection
    {
        $id = $entry->id();

        return Booklet::entries()->all()
            ->filter(fn (Entry $other) => $other->id() !== $id && self::mentions($other->data(), $id))
            ->sortByDesc(fn (Entry $e) => (string) $e->lastModified())
            ->values();
    }

    protected static function mentions(array $data, string $id): bool
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::NOT_LINKS, true)) {
                continue;
            }
            if ($value === $id || (is_array($value) && self::mentions($value, $id))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entries someone could link to, for the shelf in the editor: matching the
     * search, from the allowed chapters, newest first.
     *
     * @return Collection<int, Entry>
     */
    public static function shelf(string $search, array $field = [], array $skip = [], int $limit = 40): Collection
    {
        $allowed = self::collectionsOf($field);
        $needle = mb_strtolower(trim($search));

        return Booklet::entries()->all()
            ->filter(fn (Entry $e) => ! in_array($e->id(), $skip, true)
                && ($allowed === [] || in_array($e->collectionHandle(), $allowed, true))
                && $e->site() === config('booklet.default_site')
                && ($needle === '' || str_contains(mb_strtolower($e->title().' '.$e->slug()), $needle)))
            ->sortByDesc(fn (Entry $e) => [$e->status() === 'published' ? 1 : 0, (string) ($e->date() !== null ? $e->date()->timestamp : $e->lastModified())])
            ->take($limit)
            ->values();
    }

    /**
     * Every address on the site worth linking to (published pages and posts,
     * the blog), for "Goes to" boxes to suggest: path => title.
     *
     * @return array<string, string>
     */
    public static function addresses(int $max = 400): array
    {
        // Straight from the entries (not the site's kept list, which a request may still use after a save).
        $site = app(\App\Booklet\Site::class);
        $home = (string) $site->setting('homepage', 'posts');
        $out = [];
        foreach (rescue(fn () => Booklet::entries()->all()->filter(fn (Entry $e) => $e->status() === 'published' && $e->uri()), collect(), false) as $entry) {
            /** @var Entry $entry */
            $path = $entry->id() === $home ? '/' : (string) $entry->uri();
            if ($path !== '' && ! isset($out[$path])) {
                $out[$path] = $entry->title() ?: $path;
            }
        }
        $blog = '/'.ltrim((string) parse_url($site->blogUrl(), PHP_URL_PATH), '/');
        $out[$blog] ??= __('The blog');
        uasort($out, fn ($a, $b) => strnatcasecmp($a, $b));

        return array_slice($out, 0, $max, true);
    }
}
