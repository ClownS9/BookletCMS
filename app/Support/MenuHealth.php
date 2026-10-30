<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Booklet\Support\MenuOutline;
use App\Booklet\Support\PageTree;
use App\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What's wrong with a menu, in words: a link to a deleted or draft page, an
 * address with nothing at it, a wide panel with nothing in it, a menu bar
 * too long for smaller screens. Warnings ('warn') are things readers would
 * notice; notes ('note') are worth knowing.
 */
class MenuHealth
{
    /** More than this many items in the header bar wraps onto two lines on smaller screens. */
    public const BAR_ITEMS = 7;

    /** …or this many letters across them all. */
    public const BAR_LETTERS = 64;

    /** A drop-down longer than this is hard to use on a phone. */
    public const DROP_ITEMS = 12;

    /**
     * @param  list<array<string, mixed>>  $rows  the menu as an outline (MenuOutline)
     * @return array{rows: array<int, list<array{level: string, text: string}>>, menu: list<array{level: string, text: string}>}
     */
    public static function check(array $rows, string $handle = 'header'): array
    {
        $site = app(Site::class);
        $rows = MenuOutline::normalize($rows);
        $found = [];
        $menu = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $say = [];
            $depth = (int) $row['depth'];

            if (isset($row['entry'])) {
                $entry = Booklet::entries()->find((string) $row['entry']);
                $key = 'entry:'.$row['entry'];
                if (! $entry) {
                    $say[] = ['level' => 'warn', 'text' => __('Points at a page that’s been deleted, so it isn’t on the site. Remove it, or put the page back from the bin.')];
                } elseif ($entry->status() === 'scheduled') {
                    $say[] = ['level' => 'note', 'text' => $entry->date() ? __('Scheduled for :when: it shows up in the menu then.', ['when' => $entry->date()->timezone(config('app.timezone'))->translatedFormat('j M, g:ia')]) : __('Scheduled: it shows up in the menu then.')];
                } elseif ($entry->status() !== 'published') {
                    $say[] = ['level' => 'warn', 'text' => __('A draft, so it’s left out of the menu until it’s published.')];
                }
                if ($entry && ! empty($row['follow']) && self::publishedChildren($entry) === 0) {
                    $say[] = ['level' => 'note', 'text' => 'Brings its sub-pages along, but none are published yet.'];
                }
            } else {
                $url = (string) ($row['url'] ?? '');
                $key = 'url:'.rtrim(strtolower($url), '/');
                if (str_starts_with($url, '/') && ! self::existsHere($url, $site)) {
                    $say[] = ['level' => 'warn', 'text' => __('Nothing on this site at :address yet, so readers get “page not found”.', ['address' => Str::limit(strtok($url, '?#') ?: $url, 60)])];
                } elseif (str_starts_with(strtolower($url), 'http://')) {
                    $say[] = ['level' => 'note', 'text' => 'Not a secure (https) link. Most sites have an https address now.'];
                }
            }

            if (isset($seen[$key])) {
                $say[] = ['level' => 'note', 'text' => 'Also in this menu, higher up.'];
            }
            $seen[$key] = true;

            $children = MenuOutline::blockEnd($rows, $i) - $i - 1;
            if ($depth === 0 && ! empty($row['mega']) && $children === 0 && empty($row['follow'])
                && trim((string) ($row['blurb'] ?? '')) === '' && empty($row['picture']) && empty($row['latest'])) {
                $say[] = ['level' => 'warn', 'text' => 'A wide panel with nothing in it yet: tuck some items under it, or add a few words or a picture.'];
            }
            $direct = collect(array_slice($rows, $i + 1, $children))->where('depth', $depth + 1)->count();
            if ($direct > self::DROP_ITEMS) {
                $say[] = ['level' => 'note', 'text' => __(':count items drop down from it: a long list to scroll on a phone. Grouping some under others helps.', ['count' => $direct])];
            }

            if ($say !== []) {
                $found[$i] = $say;
            }
        }

        $top = array_values(array_filter($rows, fn ($r) => (int) $r['depth'] === 0));
        if ($handle === 'header' || str_starts_with($handle, 'header')) {
            $letters = array_sum(array_map(fn ($r) => mb_strlen(self::label($r)), $top));
            if (count($top) > self::BAR_ITEMS || $letters > self::BAR_LETTERS) {
                $menu[] = ['level' => 'warn', 'text' => __(':count items across the top (:letters letters): on smaller screens the bar wraps onto two lines. Tucking a few under others keeps it to one.', ['count' => count($top), 'letters' => $letters])];
            }
        }
        if ($rows === []) {
            $menu[] = ['level' => 'note', 'text' => 'This menu is empty, so nothing shows where it would be.'];
        }

        return ['rows' => $found, 'menu' => $menu];
    }

    /** Problems in one line each (for the check-up and Stacy). */
    public static function summary(array $rows, string $handle = 'header'): array
    {
        $result = self::check($rows, $handle);
        $lines = array_map(fn ($p) => $p['text'], $result['menu']);
        foreach ($result['rows'] as $i => $problems) {
            foreach ($problems as $p) {
                $lines[] = '“'.self::label($rows[$i]).'”: '.$p['text'];
            }
        }

        return $lines;
    }

    public static function warnings(array $rows, string $handle = 'header'): int
    {
        $result = self::check($rows, $handle);

        return collect($result['menu'])->where('level', 'warn')->count()
            + collect($result['rows'])->flatten(1)->where('level', 'warn')->count();
    }

    /** What a row is called in the menu. */
    public static function label(array $row): string
    {
        if (isset($row['title']) && $row['title'] !== '') {
            return (string) $row['title'];
        }
        if (isset($row['entry'])) {
            return Booklet::entries()->find((string) $row['entry'])?->title() ?: 'A missing page';
        }

        return (string) ($row['url'] ?? '');
    }

    /** Is there something at this address on the site (a page, a tag, the blog, a redirect, a route)? */
    protected static function existsHere(string $url, Site $site): bool
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '/') {
            return true;
        }
        if ($site->findByUri($path) || $site->findTermByUri($path)) {
            return true;
        }
        $blog = $site->blogPath();
        if ($path === $blog || str_starts_with($path, $blog.'/')) {
            return true;
        }
        if (rescue(fn () => Redirect::query()->where('from_path', $path)->exists(), false, false)) {
            return true;
        }

        // One of the site's own pages (search, the feed…), not the catch-all that finds entries.
        return rescue(function () use ($path) {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));

            return ! $route->isFallback && ! str_starts_with((string) $route->uri(), '{');
        }, false, false);
    }

    protected static function publishedChildren(Entry $entry): int
    {
        $children = collect(PageTree::for($entry->collectionHandle())->flatten())->where('parent', $entry->id())->pluck('id');

        return $children->filter(fn ($id) => Booklet::entries()->find($id)?->status() === 'published')->count();
    }
}
