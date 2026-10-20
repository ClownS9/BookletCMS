<?php

namespace App\Http\Middleware;

use App\Support\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands readers a kept copy of a public page when there is one, and keeps
 * the ones it has to make (see App\Support\PageCache).
 */
class CachePages
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PageCache::enabled() || ! PageCache::cacheable($request)) {
            return $next($request);
        }

        if ($hit = PageCache::get($request)) {
            return $hit;
        }

        $response = $next($request);
        PageCache::put($request, $response);

        return $response;
    }
}
