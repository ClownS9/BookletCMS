<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Models\Redirect;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Links that lead nowhere. Every link in every published entry is checked:
 * links to this site against the book itself (does the page exist, is it
 * live, is there a redirect?), links elsewhere by asking that site. Only
 * clear failures are reported (a page that's gone, a site that doesn't
 * answer); sites that turn robots away aren't counted as broken.
 *
 * Runs weekly (and from Redirects › Check now, or booklet:links). The last
 * report is kept, and outside answers are remembered for a few days.
 */
class LinkCheck
{
    public const REPORT = 'booklet.links.report';

    /** How long an answer from another site is trusted, in hours. */
    public const REMEMBER_HOURS = 72;

    /** Most outside addresses asked about in one go. */
    public const MAX_OUTSIDE = 400;

    /**
     * The last report: ['checked_at' => ISO time, 'links' => n, 'broken' => [...]], or null.
     */
    public static function report(): ?array
    {
        return Cache::get(self::REPORT);
    }

    /** @return array<int, array{entry_id: string, title: string, url: string, internal: bool, reason: string, path: ?string}> */
    public static function broken(): array
    {
        return (array) (self::report()['broken'] ?? []);
    }

    /**
     * Check everything now and keep the report.
     */
    public static function run(): array
    {
        $site = app(Site::class);
        $links = [];

        foreach ($site->published() as $entry) {
            foreach (self::linksIn((string) $entry->get('content', '')) as $url) {
                $links[] = ['entry' => $entry, 'url' => $url];
            }
        }

        $outside = [];
        $broken = [];

        foreach ($links as $link) {
            $internal = self::internalPath($link['url']);
            if ($internal !== null) {
                if ($reason = self::checkInternal($internal)) {
                    $broken[] = self::row($link['entry'], $link['url'], true, $reason, $internal);
                }
            } elseif (preg_match('#^https?://#i', $link['url'])) {
                $outside[$link['url']][] = $link['entry'];
            }
        }

        $answers = self::askOutside(array_slice(array_keys($outside), 0, self::MAX_OUTSIDE));
        foreach ($answers as $url => $reason) {
            if ($reason !== null) {
                foreach ($outside[$url] as $entry) {
                    $broken[] = self::row($entry, $url, false, $reason, null);
                }
            }
        }

        $report = [
            'checked_at' => now()->toIso8601String(),
            'links' => count($links),
            'broken' => array_values(collect($broken)->unique(fn ($b) => $b['entry_id'].'|'.$b['url'])->all()),
        ];
        Cache::forever(self::REPORT, $report);

        return $report;
    }

    /**
     * Every link in some Markdown (or HTML), without duplicates.
     *
     * @return string[]
     */
    public static function linksIn(string $text): array
    {
        $found = [];
        // [text](url "title") — not pictures ![alt](…), which Media looks after.
        preg_match_all('/(?<!!)\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/u', $text, $m);
        $found = array_merge($found, $m[1]);
        // <https://…> and bare https://… in text
        preg_match_all('#<(https?://[^>\s]+)>|(?<![("\'=\]])\bhttps?://[^\s<>()"\']+#iu', $text, $m);
        foreach ($m[0] as $i => $whole) {
            $found[] = $m[1][$i] !== '' ? $m[1][$i] : rtrim($whole, '.,;:!?');
        }
        // <a href="…"> in any HTML
        preg_match_all('/<a\b[^>]*\shref\s*=\s*["\']([^"\']+)["\']/iu', $text, $m);
        $found = array_merge($found, $m[1]);

        return array_values(array_unique(array_filter(array_map('trim', $found), fn ($url) => $url !== ''
            && ! str_starts_with($url, '#')
            && ! preg_match('#^(mailto|tel|sms|javascript|data):#i', $url))));
    }

    /** "/blog/spring" for a link to this site (relative or full), null for anywhere else. */
    public static function internalPath(string $url): ?string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return Redirects::normalise($url);
        }
        if (! preg_match('#^(https?:)?//#i', $url)) {
            return null; // "about/team" depends on where it's read from; left alone rather than guessed
        }

        $host = strtolower((string) parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url, PHP_URL_HOST));
        $ours = array_filter([
            strtolower((string) parse_url(app(Site::class)->absolute('/'), PHP_URL_HOST)),
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ]);

        return in_array($host, $ours, true) ? Redirects::normalise($url) : null;
    }

    /** Why a link to this site leads nowhere, or null if it's fine. */
    public static function checkInternal(string $path): ?string
    {
        $site = app(Site::class);

        if ($path === '/') {
            return null;
        }
        if ($entry = $site->findByUri($path)) {
            return $entry->status() === 'published' ? null : 'goes to a page that isn’t published';
        }
        if ($site->findTermByUri($path)) {
            return null;
        }
        if ($path === Redirects::normalise((string) parse_url($site->blogUrl(), PHP_URL_PATH))) {
            return null;
        }
        if (rescue(fn () => Redirect::where('from_path', $path)->exists(), false, false)) {
            return null; // forwarded somewhere
        }
        if (str_starts_with($path, '/storage/')) {
            return \App\Support\Disk::of('public')->exists(rawurldecode(Str::after($path, '/storage/'))) ? null : 'goes to a file that isn’t in Media any more';
        }
        // Anything else Laravel answers (search, the feed, the sitemap, sign in…).
        foreach (Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if ($route->isFallback) {
                continue;
            }
            $uri = '/'.trim($route->uri(), '/');
            if (! str_contains($uri, '{') && $uri === $path) {
                return null;
            }
        }

        return 'goes to a page that doesn’t exist';
    }

    /**
     * Ask other sites about their addresses, a few at a time.
     *
     * @param  string[]  $urls
     * @return array<string, ?string> url => why it's broken (null when fine)
     */
    public static function askOutside(array $urls): array
    {
        $answers = [];
        $ask = [];
        foreach ($urls as $url) {
            $remembered = Cache::get(self::key($url));
            if (is_array($remembered)) {
                $answers[$url] = $remembered['reason'];
            } else {
                $ask[] = $url;
            }
        }

        foreach (array_chunk($ask, 8) as $batch) {
            $responses = rescue(fn () => Http::pool(fn (Pool $pool) => array_map(
                fn ($url) => $pool->as($url)->withHeaders(['User-Agent' => 'Booklet link check (+'.url('/').')'])
                    ->timeout(10)->connectTimeout(6)->withoutRedirecting()->head($url),
                $batch,
            )), [], false);

            foreach ($batch as $url) {
                $reason = self::judge($responses[$url] ?? null, $url);
                $answers[$url] = $reason;
                Cache::put(self::key($url), ['reason' => $reason], now()->addHours(self::REMEMBER_HOURS));
            }
        }

        return $answers;
    }

    /** What an answer means. Only clear failures count. */
    protected static function judge(mixed $response, string $url, int $hops = 0): ?string
    {
        if ($response instanceof Throwable || $response === null) {
            // No answer at all: try once more, the ordinary way.
            try {
                $response = Http::withHeaders(['User-Agent' => 'Booklet link check'])->timeout(12)->connectTimeout(8)->withoutRedirecting()->get($url);
            } catch (Throwable) {
                return 'the site didn’t answer';
            }
        }

        $status = $response->status();

        // Follow a few hops ourselves, so a redirect to a missing page still counts.
        if (in_array($status, [301, 302, 303, 307, 308], true) && $hops < 4 && ($next = $response->header('Location'))) {
            $next = preg_match('#^https?://#i', $next) ? $next : rtrim((string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $url), '/').'/'.ltrim($next, '/');
            try {
                return self::judge(Http::withHeaders(['User-Agent' => 'Booklet link check'])->timeout(10)->withoutRedirecting()->head($next), $next, $hops + 1);
            } catch (Throwable) {
                return 'the site didn’t answer';
            }
        }

        // Some sites don't take HEAD: ask properly before deciding.
        if (in_array($status, [400, 404, 405, 501], true) && $hops === 0 && $response->transferStats?->getRequest()->getMethod() === 'HEAD') {
            try {
                $response = Http::withHeaders(['User-Agent' => 'Booklet link check'])->timeout(12)->get($url);
                $status = $response->status();
            } catch (Throwable) {
                return 'the site didn’t answer';
            }
        }

        return match (true) {
            in_array($status, [404, 410], true) => 'the page isn’t there any more ('.$status.')',
            $status >= 500 => null, // a bad moment for them, not necessarily a broken link
            default => null, // 2xx, 3xx, and 401/403/429 (sites turning robots away) are fine
        };
    }

    protected static function row(Entry $entry, string $url, bool $internal, string $reason, ?string $path): array
    {
        return [
            'entry_id' => (string) $entry->id(),
            'collection' => $entry->collectionHandle(),
            'title' => $entry->title() ?: 'Untitled',
            'url' => $url,
            'internal' => $internal,
            'reason' => $reason,
            'path' => $path,
        ];
    }

    protected static function key(string $url): string
    {
        return 'booklet.links.url.'.sha1($url);
    }
}
