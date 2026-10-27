<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Posts in a numbered series: "Part 2 of 5", with the parts either side.
 * A post joins one by its name (data "series"); the parts go in the order of
 * their part numbers (data "series_part"), then by date for any without one.
 */
class Series
{
    public const NAME = 'series';

    public const PART = 'series_part';

    public static function name(Entry $entry): ?string
    {
        $name = trim((string) $entry->get(self::NAME, ''));

        return $name !== '' ? $name : null;
    }

    public static function slug(string $name): string
    {
        return Str::slug($name) ?: mb_strtolower($name);
    }

    /**
     * Where this post sits in its series, among the posts readers can see
     * (the post itself counts even while it's a draft being previewed).
     *
     * @param  Collection<int, Entry>  $posts  the site's published posts
     * @return array{name: string, slug: string, part: int, count: int, parts: Collection<int, Entry>, previous: ?Entry, next: ?Entry}|null
     */
    public static function of(Entry $entry, Collection $posts): ?array
    {
        $name = self::name($entry);
        if ($name === null) {
            return null;
        }
        $slug = self::slug($name);
        $parts = $posts->filter(fn (Entry $e) => ($n = self::name($e)) !== null && self::slug($n) === $slug);
        if (! $parts->contains(fn (Entry $e) => $e->id() === $entry->id())) {
            $parts->push($entry);
        }
        $parts = self::ordered($parts);
        if ($parts->count() < 2) {
            return null; // a series of one isn't one yet
        }

        $index = (int) $parts->search(fn (Entry $e) => $e->id() === $entry->id());

        return [
            'name' => $name,
            'slug' => $slug,
            'part' => $index + 1,
            'count' => $parts->count(),
            'parts' => $parts,
            'previous' => $parts->get($index - 1),
            'next' => $parts->get($index + 1),
        ];
    }

    /**
     * @param  Collection<int, Entry>  $parts
     * @return Collection<int, Entry>
     */
    public static function ordered(Collection $parts): Collection
    {
        return $parts->sort(function (Entry $a, Entry $b) {
            $pa = self::partNumber($a);
            $pb = self::partNumber($b);
            if ($pa !== $pb) {
                return ($pa ?? PHP_INT_MAX) <=> ($pb ?? PHP_INT_MAX);
            }

            return ($a->date()?->getTimestamp() ?? 0) <=> ($b->date()?->getTimestamp() ?? 0);
        })->values();
    }

    public static function partNumber(Entry $entry): ?int
    {
        $n = $entry->get(self::PART);

        return is_numeric($n) && (int) $n > 0 ? (int) $n : null;
    }

    /**
     * Every series in the book, with how many posts are in each (drafts too),
     * for the editor's suggestions.
     *
     * @return array<string, int> name => posts
     */
    public static function all(): array
    {
        $names = [];
        foreach (rescue(fn () => Booklet::entries()->all(), collect(), false) as $entry) {
            if (($name = self::name($entry)) !== null) {
                $slug = self::slug($name);
                $names[$slug] ??= [$name, 0];
                $names[$slug][1]++;
            }
        }

        return collect($names)->sortBy(fn ($n) => mb_strtolower($n[0]))->mapWithKeys(fn ($n) => [$n[0] => $n[1]])->all();
    }
}
