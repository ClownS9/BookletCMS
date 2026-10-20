<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Faster public pages: once a page of the site has been put together, the
 * finished page is kept and handed to the next readers as it is, until
 * something in the book changes (any entry, page, menu, setting, approved
 * comment or person's name), or a scheduled post's time arrives.
 *
 * Only for readers who aren't signed in, for plain page visits (GET, no
 * query other than ?page=), and for pages that came out fine (200). Each
 * reader's own form token is put back into the page as it's served, so
 * comment, sign-up and contact forms keep working.
 */
class PageCache
{
    /** How long a page is kept at most, even if nothing changes (minutes). */
    public const MINUTES = 60 * 24;

    /** Pages bigger than this aren't kept (bytes). */
    public const MAX_BYTES = 2_000_000;

    protected const TOKEN = '__BOOKLET_FORM_TOKEN__';

    protected const VERSION_KEY = 'booklet.pagecache.version';

    protected const NEXT_KEY = 'booklet.pagecache.next';

    public static function enabled(): bool
    {
        // Read fresh (not the page's copy of the settings), so switching it off takes effect at once.
        return (bool) rescue(fn () => Booklet::globals('site')?->get('page_cache', true) ?? true, true, false);
    }

    /** Can this request be answered from (or stored in) the cache? */
    public static function cacheable(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->user() || $request->hasHeader('X-Livewire') || $request->ajax()) {
            return false;
        }
        if (array_diff(array_keys($request->query()), ['page']) !== [] || preg_match('#^(admin|livewire|login|setup|preview|storage|subscribe|unsubscribe|up)(/|$)#', $request->path())) {
            return false;
        }
        $route = $request->route();
        $name = $route instanceof \Illuminate\Routing\Route ? (string) $route->getName() : '';

        return $route instanceof \Illuminate\Routing\Route && ($route->isFallback || (str_starts_with($name, 'site.') && $name !== 'site.search'));
    }

    protected static function key(Request $request): string
    {
        return 'booklet.page.'.self::version().'.'.self::fingerprint().'.'.sha1($request->getHttpHost().'|'.$request->getRequestUri());
    }

    /**
     * Changes when the site's templates or styles change (an update to
     * Booklet, a theme edit), so pages kept from before are never served
     * with new files. A few dozen file times, looked at once per request.
     */
    public static function fingerprint(): string
    {
        if (request()->attributes->has('booklet.pagecache.print')) {
            return request()->attributes->get('booklet.pagecache.print');
        }

        // Only what the public site is made from (the admin's templates never show there).
        $times = [];
        foreach (['views/site', 'views/components/site', 'views/livewire/site'] as $dir) {
            $dir = resource_path($dir);
            if (is_dir($dir)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    $times[] = $file->getMTime();
                }
            }
        }
        foreach (['booklet/site.css', 'booklet/site.js'] as $file) {
            $times[] = (int) @filemtime(public_path($file));
        }

        $print = substr(md5(count($times).'|'.max($times ?: [0]).'|'.array_sum($times)), 0, 8);
        request()->attributes->set('booklet.pagecache.print', $print);

        return $print;
    }

    public static function version(): int
    {
        // A scheduled post's time has come: everything is put together again.
        $next = Cache::get(self::NEXT_KEY);
        if ($next && now()->timestamp >= (int) $next) {
            self::flush();
        }

        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /** A kept page for this request, with this reader's form token, or null. */
    public static function get(Request $request): ?Response
    {
        $hit = Cache::get(self::key($request));
        if (! is_array($hit) || ! isset($hit['body'], $hit['type'])) {
            return null;
        }

        $body = str_replace(self::TOKEN, (string) csrf_token(), $hit['body']);

        // Which entry the page is, for the visit stats (the controller that says so didn't run).
        if (! empty($hit['entry'])) {
            $request->attributes->set('booklet_entry', $hit['entry']);
        }

        return new \Illuminate\Http\Response($body, 200, ['Content-Type' => $hit['type'], 'X-Booklet-Cache' => 'hit']);
    }

    /** Keep a finished page (if it's one worth keeping). */
    public static function put(Request $request, Response $response): void
    {
        $type = (string) $response->headers->get('Content-Type');
        $body = $response->getContent();
        if ($response->getStatusCode() !== 200 || ! is_string($body) || strlen($body) > self::MAX_BYTES
            || ! preg_match('#^(text/html|application/(rss\+)?xml|text/xml)#', $type)
            || $response->headers->has('Set-Cookie') && self::setsOwnCookies($response)) {
            return;
        }

        $token = (string) csrf_token();
        if ($token !== '') {
            $body = str_replace($token, self::TOKEN, $body);
        }

        if (! Cache::has(self::NEXT_KEY)) {
            self::rememberNextScheduled();
        }

        Cache::put(self::key($request), ['body' => $body, 'type' => $type, 'entry' => $request->attributes->get('booklet_entry')], now()->addMinutes(self::MINUTES));
        $response->headers->set('X-Booklet-Cache', 'miss');
    }

    /** Cookies other than the session's and the form token's mean the page was made for one person. */
    protected static function setsOwnCookies(Response $response): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (! in_array($cookie->getName(), [config('session.cookie'), 'XSRF-TOKEN'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Forget every kept page (they're put together again as readers arrive). */
    public static function flush(): void
    {
        rescue(function () {
            Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 1) + 1);
            Cache::forget(self::NEXT_KEY);
        }, null, false);
    }

    /** When the next scheduled entry goes out, so pages are made fresh then. */
    protected static function rememberNextScheduled(): void
    {
        $next = rescue(fn () => Booklet::entries()->all()
            ->filter(fn (Entry $e) => $e->status() === 'scheduled')
            ->map(fn (Entry $e) => $e->date())
            ->filter()
            ->min(), null, false);

        Cache::put(self::NEXT_KEY, $next instanceof Carbon ? $next->timestamp : now()->addYears(5)->timestamp, now()->addMinutes(self::MINUTES));
    }

    /** Something in the book changed. Which changes reach the public site? */
    public static function changed(string $what): void
    {
        if (! in_array($what, ['revisions', 'form_submissions'], true)) {
            self::flush();
        }
    }
}
