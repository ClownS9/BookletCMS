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
     * The site's own menus (not their seasonal versions).
     *
     * @return array<string, string> handle => title
     */
    public static function all(): array
    {
        $menus = Booklet::navigations()->all()
            ->reject(fn ($nav) => is_array($nav->get('season')))
            ->mapWithKeys(fn ($nav) => [$nav->handle() => $nav->title()])
            ->all();

        return $menus ?: ['header' => 'Header menu', 'footer' => 'Footer menu'];
    }

    /**
     * Every menu, seasonal versions included.
     *
     * @return array<string, string> handle => title
     */
    public static function allWithSeasons(): array
    {
        return static::all() + Booklet::navigations()->all()
            ->filter(fn ($nav) => is_array($nav->get('season')))
            ->mapWithKeys(fn ($nav) => [$nav->handle() => $nav->title()])
            ->all();
    }

    // Seasonal menus: a version of a menu that takes its place between two dates ----------------

    /**
     * The seasonal versions of a menu (or of every menu), soonest first.
     * Each: handle, title, for, from, until (dates, Y-m-d, in the site's time).
     *
     * @return list<array{handle: string, title: string, for: string, from: string, until: string}>
     */
    public static function seasons(?string $for = null): array
    {
        return Booklet::navigations()->all()
            ->filter(fn ($nav) => is_array($nav->get('season')) && ($for === null || ($nav->get('season')['for'] ?? null) === $for))
            ->map(fn ($nav) => [
                'handle' => $nav->handle(),
                'title' => $nav->title(),
                'for' => (string) ($nav->get('season')['for'] ?? ''),
                'from' => (string) ($nav->get('season')['from'] ?? ''),
                'until' => (string) ($nav->get('season')['until'] ?? ''),
            ])
            ->sortBy('from')->values()->all();
    }

    /** One seasonal menu's details, or null when it isn't one. */
    public static function season(string $handle): ?array
    {
        return collect(static::seasons())->firstWhere('handle', $handle);
    }

    /** "Today" in the site's own time zone, as Y-m-d. */
    protected static function today(?\DateTimeInterface $now = null): string
    {
        return \Illuminate\Support\Carbon::instance($now ?? now())->timezone((string) config('app.timezone', 'UTC'))->toDateString();
    }

    /** The seasonal menu standing in for this one today, if any (the one that started last wins). */
    public static function activeSeason(string $for, ?\DateTimeInterface $now = null): ?string
    {
        $today = static::today($now);

        return collect(static::seasons($for))
            ->filter(fn ($s) => $s['from'] !== '' && $s['until'] !== '' && $s['from'] <= $today && $today <= $s['until'])
            ->sortByDesc('from')->first()['handle'] ?? null;
    }

    /** Is it on now, coming, or over? */
    public static function seasonState(array $season, ?\DateTimeInterface $now = null): string
    {
        $today = static::today($now);

        return $today < $season['from'] ? 'coming' : ($today > $season['until'] ? 'over' : 'on');
    }

    /** The next moment any seasonal menu starts or stops (so kept pages are made fresh then). */
    public static function nextSeasonChange(?\DateTimeInterface $now = null): ?\Illuminate\Support\Carbon
    {
        $zone = (string) config('app.timezone', 'UTC');
        $now = \Illuminate\Support\Carbon::instance($now ?? now());

        return collect(static::seasons())
            ->flatMap(fn ($s) => [
                rescue(fn () => \Illuminate\Support\Carbon::parse($s['from'], $zone)->startOfDay(), null, false),
                rescue(fn () => \Illuminate\Support\Carbon::parse($s['until'], $zone)->addDay()->startOfDay(), null, false),
            ])
            ->filter(fn ($at) => $at && $at->greaterThan($now))
            ->sort()->first();
    }

    /**
     * A seasonal version of a menu: starts as a copy of it. Returns its handle.
     */
    public static function addSeason(string $for, string $name, string $from, string $until): string
    {
        $base = \Illuminate\Support\Str::slug($for.' '.$name) ?: $for.'-season';
        $handle = $base;
        for ($i = 2; Booklet::navigations()->find($handle); $i++) {
            $handle = $base.'-'.$i;
        }

        Booklet::navigations()->make(
            ['handle' => $handle, 'title' => trim($name)],
            ['max_depth' => MenuOutline::MAX_DEPTH + 1, 'season' => ['for' => $for, 'from' => $from, 'until' => $until]],
        )->save();
        Booklet::trees()->findOrMake('navigation', $handle)->setTree(static::items($for))->save();

        return $handle;
    }

    public static function setSeasonDates(string $handle, string $from, string $until): void
    {
        $nav = Booklet::navigations()->find($handle);
        if ($nav && is_array($season = $nav->get('season'))) {
            $nav->set('season', ['from' => $from, 'until' => $until] + $season)->save();
        }
    }

    /** Throw a seasonal menu away (only a seasonal one). */
    public static function deleteSeason(string $handle): void
    {
        $nav = Booklet::navigations()->find($handle);
        if (! $nav || ! is_array($nav->get('season'))) {
            return;
        }
        Booklet::trees()->navigation($handle)?->delete();
        $nav->delete();
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
        foreach (array_keys(static::allWithSeasons()) as $handle) {
            if (static::has($handle, $entryId)) {
                static::remove($handle, $entryId);
            }
        }
    }
}
