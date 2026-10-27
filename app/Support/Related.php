<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use Illuminate\Support\Collection;

/**
 * "More like this" at the end of a post: posts picked by hand in its
 * "related" field first, then the ones sharing the most categories (worth 3)
 * and tags (worth 2), newest first among equals, then the newest posts if
 * there still aren't enough. Never itself, nor others in its own series
 * (they have their own links).
 */
class Related
{
    /**
     * @param  Collection<int, Entry>  $posts  the site's published posts, newest first
     * @return Collection<int, Entry>
     */
    public static function for(Entry $entry, Collection $posts, int $take = 3): Collection
    {
        $series = Series::name($entry);
        $seriesSlug = $series !== null ? Series::slug($series) : null;
        $candidates = $posts->reject(fn (Entry $e) => $e->id() === $entry->id()
            || ($seriesSlug !== null && ($n = Series::name($e)) !== null && Series::slug($n) === $seriesSlug))->values();

        // Picked by hand: as picked (pages too), as long as readers can see them.
        $picked = rescue(fn () => Links::entries($entry->get('related')), collect(), false)
            ->reject(fn (Entry $e) => $e->id() === $entry->id())->values();

        $categories = (array) $entry->get('categories', []);
        $tags = (array) $entry->get('tags', []);
        $scored = $candidates
            ->map(fn (Entry $e, int $i) => [$e, 3 * count(array_intersect($categories, (array) $e->get('categories', [])))
                + 2 * count(array_intersect($tags, (array) $e->get('tags', []))), $i])
            ->filter(fn ($s) => $s[1] > 0)
            ->sort(fn ($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]) // more in common first, then newer
            ->map(fn ($s) => $s[0]);

        return $picked->concat($scored)->concat($candidates)
            ->unique(fn (Entry $e) => $e->id())
            ->take($take)
            ->values();
    }
}
