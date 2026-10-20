<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts page views on the public site (per day, per page, and where readers
 * came from) for the dashboard and Visits. No cookies; see App\Support\VisitStats.
 * Skips signed-in people, previews, bots and anything that isn't a page.
 */
class CountVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->counts($request, $response)) {
            try {
                \App\Support\VisitStats::record($request);
            } catch (Throwable) {
                // Never let counting get in the way of the page.
            }
        }

        return $response;
    }

    protected function counts(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && ! $request->user()
            && ! $request->is('admin', 'admin/*', 'preview/*', 'livewire/*', 'feed.xml', 'sitemap.xml', 'login', 'setup', 'up')
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && ! preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless/i', (string) $request->userAgent());
    }
}
