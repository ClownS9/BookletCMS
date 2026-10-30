<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Counting visits to the public site, privately: no cookies, no addresses,
 * nothing that follows a reader from one day to the next.
 */
class VisitStats
{
    /** A page view: the day's total, the page, where the reader came from, and roughly how many readers. */
    public static function record(Request $request): void
    {
        $day = now()->toDateString();

        DB::table('site_visits')->insertOrIgnore(['day' => $day, 'views' => 0]);
        DB::table('site_visits')->where('day', $day)->increment('views');

        // Readers who ask not to be tracked count in the total, and nothing else.
        if ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1') {
            return;
        }

        $path = static::path($request->path());
        $entry = $request->attributes->get('booklet_entry');
        DB::table('page_stats')->insertOrIgnore(['day' => $day, 'path' => $path, 'entry_id' => $entry, 'views' => 0, 'reads' => 0]);
        DB::table('page_stats')->where('day', $day)->where('path', $path)->increment('views');
        if ($entry) {
            // The day's first view may not have known which entry it was.
            DB::table('page_stats')->where('day', $day)->where('path', $path)->whereNull('entry_id')->update(['entry_id' => $entry]);
        }

        $source = static::source($request);
        DB::table('referrer_stats')->insertOrIgnore(['day' => $day, 'source' => $source, 'views' => 0]);
        DB::table('referrer_stats')->where('day', $day)->where('source', $source)->increment('views');

        // When (the hour, in the site's own time) and on what: plain counts, nothing about who.
        $hour = (int) now()->format('G');
        DB::table('visit_hours')->insertOrIgnore(['day' => $day, 'hour' => $hour, 'views' => 0]);
        DB::table('visit_hours')->where('day', $day)->where('hour', $hour)->increment('views');
        $device = static::device((string) $request->userAgent());
        DB::table('device_stats')->insertOrIgnore(['day' => $day, 'device' => $device, 'views' => 0]);
        DB::table('device_stats')->where('day', $day)->where('device', $device)->increment('views');

        // A fingerprint that changes every day: the same reader today counts once,
        // and tomorrow they're a stranger again. Only the scrambled 16 characters are kept.
        $hash = substr(hash_hmac('sha256', $day.'|'.$request->ip().'|'.$request->userAgent(), (string) config('app.key')), 0, 16);
        if (DB::table('visitor_days')->insertOrIgnore(['day' => $day, 'hash' => $hash])) {
            DB::table('site_visits')->where('day', $day)->increment('visitors');
        }

        // The same, for this entry: where its readers came from, and roughly how many.
        if ($entry) {
            DB::table('entry_sources')->insertOrIgnore(['day' => $day, 'entry_id' => $entry, 'source' => $source, 'views' => 0]);
            DB::table('entry_sources')->where('day', $day)->where('entry_id', $entry)->where('source', $source)->increment('views');
            if (DB::table('entry_reader_days')->insertOrIgnore(['day' => $day, 'entry_id' => $entry, 'hash' => $hash])) {
                DB::table('page_stats')->where('day', $day)->where('path', $path)->increment('readers');
            }
        }
    }

    /** Someone read most of the way through a page (sent by the site's script). */
    public static function read(string $path): void
    {
        DB::table('page_stats')->where('day', now()->toDateString())->where('path', static::path($path))->increment('reads');
    }

    public static function path(string $path): string
    {
        $path = '/'.trim(Str::before($path, '?'), '/');

        return Str::limit($path, 250, '');
    }

    /** Where they came from, in plain words: "Google", "Facebook", "example.com", "Direct". */
    public static function source(Request $request): string
    {
        if ($utm = trim((string) $request->query('utm_source', $request->query('ref', '')))) {
            return Str::limit(Str::lower($utm), 60, '');
        }

        $host = Str::lower((string) parse_url((string) $request->headers->get('referer'), PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $host);

        if ($host === '' || $host === Str::lower((string) $request->getHost())) {
            return 'Direct';
        }

        $known = [
            'google.' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo', 'search.yahoo' => 'Yahoo',
            'ecosia.org' => 'Ecosia', 'facebook.com' => 'Facebook', 'fb.me' => 'Facebook', 'instagram.com' => 'Instagram',
            't.co' => 'X (Twitter)', 'twitter.com' => 'X (Twitter)', 'x.com' => 'X (Twitter)', 'linkedin.com' => 'LinkedIn',
            'lnkd.in' => 'LinkedIn', 'reddit.com' => 'Reddit', 'threads.net' => 'Threads', 'pinterest.' => 'Pinterest',
            'youtube.com' => 'YouTube', 'tiktok.com' => 'TikTok', 'whatsapp.com' => 'WhatsApp', 'wa.me' => 'WhatsApp',
            'telegram.' => 'Telegram', 't.me' => 'Telegram', 'news.ycombinator.com' => 'Hacker News', 'chatgpt.com' => 'ChatGPT',
            'perplexity.ai' => 'Perplexity', 'claude.ai' => 'Claude',
        ];
        foreach ($known as $needle => $name) {
            if (str_starts_with($host, $needle) || str_contains($host, '.'.$needle) || ($needle === $host)) {
                return $name;
            }
        }

        return Str::limit($host, 60, '');
    }

    /** "phone", "tablet" or "computer", from the browser's own description of itself. */
    public static function device(string $agent): string
    {
        return match (true) {
            (bool) preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $agent) => 'tablet',
            (bool) preg_match('/mobi|iphone|ipod|android|windows phone|opera mini/i', $agent) => 'phone',
            default => 'computer',
        };
    }

    /**
     * When people read, over the last N days, in the site's own time.
     *
     * @return array{hours: int[], week: array<int, int[]>, busiest: ?int}
     *   hours: views in each hour of the day (0–23); week: Monday to Sunday,
     *   each split into twelve two-hour blocks; busiest: the busiest hour.
     */
    public static function hours(int $days): array
    {
        $hours = array_fill(0, 24, 0);
        $week = array_fill(0, 7, array_fill(0, 12, 0));

        $rows = rescue(fn () => DB::table('visit_hours')->where('day', '>=', now()->subDays($days - 1)->toDateString())->get(['day', 'hour', 'views']), collect(), false);
        foreach ($rows as $row) {
            $hour = max(0, min(23, (int) $row->hour));
            $hours[$hour] += (int) $row->views;
            $week[Carbon::parse($row->day)->dayOfWeekIso - 1][intdiv($hour, 2)] += (int) $row->views;
        }

        return ['hours' => $hours, 'week' => $week, 'busiest' => max($hours) > 0 ? (int) array_search(max($hours), $hours, true) : null];
    }

    /**
     * On what people read, over the last N days: views per kind of device.
     *
     * @return array{phone: int, computer: int, tablet: int}
     */
    public static function devices(int $days): array
    {
        $counts = rescue(fn () => DB::table('device_stats')->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->selectRaw('device, sum(views) as views')->groupBy('device')->pluck('views', 'device')->all(), [], false);

        return [
            'phone' => (int) ($counts['phone'] ?? 0),
            'computer' => (int) ($counts['computer'] ?? 0),
            'tablet' => (int) ($counts['tablet'] ?? 0),
        ];
    }

    /** Fingerprints are only needed for the day they're made. */
    public static function tidy(): void
    {
        DB::table('visitor_days')->where('day', '<', now()->subDays(2)->toDateString())->delete();
        DB::table('entry_reader_days')->where('day', '<', now()->subDays(2)->toDateString())->delete();
    }

    // Reading the numbers back ---------------------------------------------

    /**
     * @return array{days: array, views: int, visitors: int, reads: int, pages: array, sources: array, previous: int}
     */
    public static function summary(int $days): array
    {
        $from = now()->subDays($days - 1)->toDateString();
        $prevFrom = now()->subDays($days * 2 - 1)->toDateString();

        $daily = DB::table('site_visits')->where('day', '>=', $from)->get()->keyBy(fn ($r) => Carbon::parse($r->day)->toDateString());
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $series[] = ['day' => $d, 'views' => (int) ($daily[$d]->views ?? 0), 'visitors' => (int) ($daily[$d]->visitors ?? 0)];
        }

        $pages = DB::table('page_stats')->where('day', '>=', $from)
            ->selectRaw('path, max(entry_id) as entry_id, sum(views) as views, sum(reads) as reads')
            ->groupBy('path')->orderByDesc('views')->limit(10)->get()
            ->map(fn ($r) => ['path' => $r->path, 'entry_id' => $r->entry_id, 'views' => (int) $r->views, 'reads' => (int) $r->reads])->all();

        $sources = DB::table('referrer_stats')->where('day', '>=', $from)
            ->selectRaw('source, sum(views) as views')->groupBy('source')->orderByDesc('views')->limit(8)->get()
            ->map(fn ($r) => ['source' => $r->source, 'views' => (int) $r->views])->all();

        return [
            'days' => $series,
            'views' => array_sum(array_column($series, 'views')),
            'visitors' => array_sum(array_column($series, 'visitors')),
            'reads' => (int) DB::table('page_stats')->where('day', '>=', $from)->sum('reads'),
            'previous' => (int) DB::table('site_visits')->where('day', '>=', $prevFrom)->where('day', '<', $from)->sum('views'),
            'pages' => $pages,
            'sources' => $sources,
        ];
    }

    /**
     * How one post or page is being read.
     *
     * @return array{days: array, views: int, readers: int, reads: int, rate: ?int, previous: int, sources: array, total: int, since: ?string, best: ?array}
     */
    public static function forEntry(string $entryId, int $days = 30): array
    {
        $from = now()->subDays($days - 1)->toDateString();
        $prevFrom = now()->subDays($days * 2 - 1)->toDateString();

        $rows = DB::table('page_stats')->where('entry_id', $entryId)->where('day', '>=', $from)
            ->selectRaw('day, sum(views) as views, sum(reads) as reads, sum(readers) as readers')->groupBy('day')->get()
            ->keyBy(fn ($r) => Carbon::parse($r->day)->toDateString());

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $series[] = ['day' => $d, 'views' => (int) ($rows[$d]->views ?? 0), 'reads' => (int) ($rows[$d]->reads ?? 0)];
        }

        $views = array_sum(array_column($series, 'views'));
        $reads = (int) $rows->sum('reads');
        $all = DB::table('page_stats')->where('entry_id', $entryId)->selectRaw('sum(views) as views, min(day) as since')->first();
        $best = collect($series)->sortByDesc('views')->first();

        return [
            'days' => $series,
            'views' => $views,
            'readers' => (int) $rows->sum('readers'),
            'reads' => $reads,
            // Read to the end, of the views: only worth saying with a few views to go on.
            'rate' => $views >= 5 ? (int) round(min(1, $reads / $views) * 100) : null,
            'previous' => (int) DB::table('page_stats')->where('entry_id', $entryId)->where('day', '>=', $prevFrom)->where('day', '<', $from)->sum('views'),
            'sources' => DB::table('entry_sources')->where('entry_id', $entryId)->where('day', '>=', $from)
                ->selectRaw('source, sum(views) as views')->groupBy('source')->orderByDesc('views')->limit(5)->get()
                ->map(fn ($r) => ['source' => $r->source, 'views' => (int) $r->views])->all(),
            'total' => (int) ($all->views ?? 0),
            'since' => $all->since ?? null,
            'best' => $best && $best['views'] > 0 ? $best : null,
        ];
    }

    /**
     * Views in the last N days for many entries at once (for lists).
     *
     * @param  string[]  $ids
     * @return array<string, array{views: int, reads: int}>
     */
    public static function forEntries(array $ids, int $days = 30): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('page_stats')->whereIn('entry_id', $ids)->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->selectRaw('entry_id, sum(views) as views, sum(reads) as reads')->groupBy('entry_id')->get()
            ->mapWithKeys(fn ($r) => [$r->entry_id => ['views' => (int) $r->views, 'reads' => (int) $r->reads]])->all();
    }
}
