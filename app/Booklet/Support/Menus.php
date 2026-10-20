<?php

namespace App\Booklet\Support;

use App\Booklet\Facades\Booklet;

/**
 * The site's menus (navigation trees): what's in them, adding and removing
 * a page, and tidying up when a page is deleted.
 */
class Menus
{
    /**
     * @return array<string, string> handle => title
     */
    public static function all(): array
    {
        $menus = Booklet::navigations()->all()
            ->mapWithKeys(fn ($nav) => [$nav->handle() => $nav->title()])
            ->all();

        return $menus ?: ['header' => 'Header menu', 'footer' => 'Footer menu'];
    }

    public static function items(string $handle): array
    {
        return Booklet::trees()->navigation($handle)?->tree() ?? [];
    }

    public static function save(string $handle, array $items): void
    {
        if (! Booklet::navigations()->find($handle)) {
            Booklet::navigations()->make(['handle' => $handle, 'title' => ucfirst($handle).' menu'], ['max_depth' => MenuOutline::MAX_DEPTH + 1])->save();
        }

        Booklet::trees()->findOrMake('navigation', $handle)->setTree(array_values($items))->save();
    }

    public static function has(string $handle, string $entryId): bool
    {
        $found = false;
        $walk = function (array $items) use (&$walk, &$found, $entryId) {
            foreach ($items as $item) {
                if (($item['entry'] ?? null) === $entryId) {
                    $found = true;
                }
                $walk((array) ($item['children'] ?? []));
            }
        };
        $walk(static::items($handle));

        return $found;
    }

    public static function add(string $handle, string $entryId): void
    {
        if (! static::has($handle, $entryId)) {
            static::save($handle, array_merge(static::items($handle), [['entry' => $entryId]]));
        }
    }

    public static function remove(string $handle, string $entryId): void
    {
        $strip = function (array $items) use (&$strip, $entryId) {
            $out = [];
            foreach ($items as $item) {
                if (($item['entry'] ?? null) === $entryId) {
                    continue;
                }
                if (! empty($item['children'])) {
                    $item['children'] = $strip($item['children']);
                }
                $out[] = $item;
            }

            return $out;
        };

        static::save($handle, $strip(static::items($handle)));
    }

    /**
     * A page was torn out: take it out of every menu too.
     */
    public static function forgetEntry(string $entryId): void
    {
        foreach (array_keys(static::all()) as $handle) {
            if (static::has($handle, $entryId)) {
                static::remove($handle, $entryId);
            }
        }
    }
}
