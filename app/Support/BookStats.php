<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the Contents spread: this week against last, the month's
 * visits drawn in ink, where readers came from, when they read, and the days
 * something went out. Everything comes from counts Booklet already keeps.
 */
class BookStats
{
    /**
     * Four figures for this week (the last seven days, today included), each
     * against the seven days before, with a bar for every day.
     *
     * @param  Collection<int, Entry>  $entries
     * @return array<int, array{key: string, label: string, n: int, change: ?int, delta: string, trend: string, tone: string, href: ?string, days: array<int, array{day: string, n: int}>}>
     */
    public static function week(Collection $entries): array
    {
        $days = collect(range(13, 0))->map(fn ($ago) => now()->subDays($ago)->toDateString())->all();
        $daily = rescue(fn () => DB::table('site_visits')->whereIn('day', $days)->get()->keyBy(fn ($r) => Carbon::parse($r->day)->toDateString()), collect(), false);
        $views = array_map(fn ($d) => (int) ($daily[$d]->views ?? 0), $days);
        $readers = array_map(fn ($d) => (int) ($daily[$d]->visitors ?? 0), $days);

        $published = array_fill_keys($days, 0);
        foreach ($entries as $entry) {
            $date = $entry->published() && $entry->collection()?->dated() ? $entry->date()?->toDateString() : null;
            if ($date !== null && isset($published[$date])) {
                $published[$date]++;
            }
        }

        $comments = array_fill_keys($days, 0);
        // Real comments only: spam doesn't count.
        $rows = rescue(fn () => Comment::query()->where('created_at', '>=', now()->subDays(13)->startOfDay())->where('status', '!=', 'spam')
            ->get(['created_at'])->countBy(fn ($c) => $c->created_at->toDateString())->all(), [], false);
        foreach ($rows as $day => $n) {
            if (isset($comments[$day])) {
                $comments[$day] = (int) $n;
            }
        }

        return [
            static::figure('visits', __('Visits'), $views, $days, 'coral', route('admin.visits')),
            static::figure('readers', __('Readers'), $readers, $days, 'sky', route('admin.visits')),
            static::figure('published', __('Posts out'), array_values($published), $days, 'sun',
                rescue(fn () => Booklet::collections()->find('posts') ? route('admin.entries', 'posts') : null, null, false)),
            static::figure('comments', __('Comments'), array_values($comments), $days, 'rose', route('admin.comments')),
        ];
    }

    /**
     * @param  int[]  $fourteen  the last fourteen days, oldest first
     * @param  string[]  $days
     */
    protected static function figure(string $key, string $label, array $fourteen, array $days, string $tone, ?string $href): array
    {
        $before = array_sum(array_slice($fourteen, 0, 7));
        $now = array_sum(array_slice($fourteen, 7));
        $change = $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
        $diff = $now - $before;

        // Big numbers say how much in percent; small ones just say how many more or fewer.
        $delta = match (true) {
            $now === 0 && $before === 0 => __('none yet'),
            $diff === 0 => __('same as last week'),
            $before >= 20 && $change !== null => ($diff > 0 ? '▲ ' : '▼ ').abs($change).'%',
            default => ($diff > 0 ? '▲ ' : '▼ ').abs($diff),
        };

        return [
            'key' => $key,
            'label' => $label,
            'n' => $now,
            'change' => $change,
            'delta' => $delta,
            'trend' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
            'tone' => $tone,
            'href' => $href,
            'days' => array_map(fn ($day, $n) => ['day' => Carbon::parse($day)->translatedFormat('D'), 'date' => Carbon::parse($day)->translatedFormat('D j M'), 'n' => $n], array_slice($days, 7), array_slice($fourteen, 7)),
        ];
    }

    /**
     * The last thirty days of visits as an ink line, with the thirty before as
     * a dashed one, drawn for a 400 × 170 box.
     *
     * @return array{total: int, previous: int, change: ?int, line: string, area: string, prev: string, peak: ?array{x: float, y: float, views: int, date: string}, grid: array<int, array{y: float, label: string}>, first: string, middle: string}
     */
    public static function month(): array
    {
        $days = collect(range(59, 0))->map(fn ($ago) => now()->subDays($ago)->toDateString())->all();
        $counts = rescue(fn () => DB::table('site_visits')->where('day', '>=', $days[0])->pluck('views', 'day')
            ->mapWithKeys(fn ($v, $d) => [Carbon::parse($d)->toDateString() => (int) $v])->all(), [], false);
        $series = array_map(fn ($d) => (int) ($counts[$d] ?? 0), $days);
        $before = array_slice($series, 0, 30);
        $now = array_slice($series, 30);
        $nowDays = array_slice($days, 30);

        $top = max(1, ...$now, ...$before);
        $scale = static::niceCeiling($top);
        $x = fn (int $i) => round(30 + $i * (366 / 29), 1);
        $y = fn (int $v) => round(150 - ($v / $scale) * 140, 1);
        $path = fn (array $vals) => 'M'.implode(' L', array_map(fn ($v, $i) => $x($i).' '.$y($v), $vals, array_keys($vals)));

        $line = $path($now);
        $peakIndex = array_search(max($now), $now, true);
        $total = array_sum($now);
        $previous = array_sum($before);

        return [
            'total' => $total,
            'previous' => $previous,
            'change' => $previous > 0 ? (int) round(($total - $previous) / $previous * 100) : null,
            'line' => $line,
            'area' => $line.' L396 150 L30 150 Z',
            'prev' => $path($before),
            'peak' => max($now) > 0 ? ['x' => $x((int) $peakIndex), 'y' => $y(max($now)), 'views' => max($now), 'date' => Carbon::parse($nowDays[$peakIndex])->translatedFormat('D j M')] : null,
            'grid' => array_map(fn ($v) => ['y' => $y($v), 'label' => number_format($v)], [0, intdiv($scale, 2), $scale]),
            'first' => Carbon::parse($nowDays[0])->translatedFormat('j M'),
            'middle' => Carbon::parse($nowDays[15])->translatedFormat('j M'),
        ];
    }

    /** A round number at or above $n, for the top of a chart: 10, 20, 50, 100, 200… */
    public static function niceCeiling(int $n): int
    {
        $n = max(4, $n);
        $power = 10 ** (int) floor(log10($n));
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            if ($n <= $step * $power) {
                return (int) ($step * $power);
            }
        }

        return 10 * $power;
    }

    /**
     * Where readers came from in the last thirty days: the top five, then the rest.
     *
     * @return array<int, array{name: string, views: int, share: int, tone: string}>
     */
    public static function sources(int $days = 30): array
    {
        $rows = rescue(fn () => DB::table('referrer_stats')->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->selectRaw('source, sum(views) as views')->groupBy('source')->orderByDesc('views')->get(), collect(), false);
        $total = (int) $rows->sum('views');
        if ($total === 0) {
            return [];
        }

        $tones = ['sky', 'sun', 'rose', 'lilac', 'mint'];
        $list = $rows->take(5)->values()->map(fn ($r, $i) => [
            'name' => $r->source === 'Direct' ? __('Typed it in') : $r->source,
            'views' => (int) $r->views,
            'tone' => $tones[$i],
        ])->all();
        if ($rows->count() > 5) {
            $list[] = ['name' => __('Elsewhere'), 'views' => (int) $rows->slice(5)->sum('views'), 'tone' => 'sand'];
        }

        // Shares that add up to 100, so the strip is always full.
        $left = 100;
        foreach ($list as $i => &$item) {
            $item['share'] = $i === array_key_last($list) ? $left : (int) round($item['views'] / $total * 100);
            $left -= $item['share'];
        }
        unset($item);

        return array_values(array_filter($list, fn ($i) => $i['share'] > 0));
    }

    /**
     * Something published, day by day, for the last four weeks (Monday first).
     *
     * @param  Collection<int, Entry>  $entries
     * @return array{days: array<int, array{date: string, n: int, level: int, future: bool}>, active: int, streak: int}
     */
    public static function publishing(Collection $entries): array
    {
        $start = now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->subWeeks(3);
        $counts = [];
        foreach ($entries as $entry) {
            if ($entry->published() && $entry->collection()?->dated() && ($date = $entry->date()) && $date->gte($start) && $date->lte(now())) {
                $counts[$date->toDateString()] = ($counts[$date->toDateString()] ?? 0) + 1;
            }
        }

        $days = [];
        for ($i = 0; $i < 28; $i++) {
            $day = $start->copy()->addDays($i);
            $n = $counts[$day->toDateString()] ?? 0;
            $days[] = ['date' => $day->translatedFormat('D j M'), 'n' => $n, 'level' => min(3, $n), 'future' => $day->isAfter(now()->endOfDay())];
        }

        // How many days in a row, up to today (or yesterday, if nothing yet today).
        $streak = 0;
        $cursor = now()->copy();
        if (! isset($counts[$cursor->toDateString()])) {
            $cursor->subDay();
        }
        while (isset($counts[$cursor->toDateString()])) {
            $streak++;
            $cursor->subDay();
        }

        return ['days' => $days, 'active' => count($counts), 'streak' => $streak];
    }

    /**
     * The most read pages in the last N days, named by their entries.
     *
     * @return array<int, array{title: string, views: int, share: int, href: ?string}>
     */
    public static function mostRead(int $days = 7, int $limit = 5): array
    {
        $pages = array_slice(rescue(fn () => VisitStats::summary($days)['pages'], [], false), 0, $limit);
        $top = max(1, ...(array_column($pages, 'views') ?: [1]));

        return array_map(function ($page) use ($top) {
            $entry = $page['entry_id'] ? rescue(fn () => Booklet::entries()->find($page['entry_id']), null, false) : null;

            return [
                'title' => $entry?->title() ?: ($page['path'] === '/' ? __('Home page') : $page['path']),
                'views' => $page['views'],
                'share' => (int) round($page['views'] / $top * 100),
                'href' => $entry ? route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()]) : null,
            ];
        }, $pages);
    }
}
