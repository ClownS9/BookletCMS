<?php

namespace App\Http\Middleware;

use App\Support\StructuredData;
use App\Themes\ThemeManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public pages wear the chosen theme: its templates are put in front of
 * Booklet's own while the page is made. If one of them breaks, the page is
 * made again with Booklet's own, so readers never see the theme's mistake,
 * and what went wrong is kept for The cover and the Check-up.
 */
class ApplyTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin', 'admin/*', 'livewire*', 'api/*')) {
            ThemeManager::apply(null);

            return $next($request);
        }

        $theme = rescue(fn () => ThemeManager::current(), null, false);
        ThemeManager::apply($theme);

        $response = $next($request);

        $failed = $response->exception ?? null;
        if ($theme && ThemeManager::applied() === $theme->key && $failed instanceof \Throwable && ThemeManager::causedBy($theme, $failed)) {
            ThemeManager::noteError($theme, $failed, $request->path());
            ThemeManager::apply(null);
            StructuredData::reset();
            $response = $next($request);
            $response->headers->set('X-Booklet-Theme', 'fell back to Booklet’s own templates');
        }

        return $response;
    }
}
