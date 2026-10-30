<?php

namespace App\Http\Middleware;

use App\Support\Upkeep;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While readers are kept away (php booklet down, or Settings › Upkeep),
 * the public site answers with a "back soon" page. Everyone signed in to the
 * book carries on: the admin, signing in, previews, the page builder, and
 * the site itself, so they can check their work before letting readers in.
 */
class ReadersAway
{
    /** Always reachable, so the people of the book can get in and work. */
    protected const OPEN = [
        'admin', 'admin/*', 'login', 'logout', 'setup', 'reset-password/*', 'forgot-password', 'two-factor*',
        'preview/*', 'builder/*', 'livewire/*', 'install/*', 'up', 'storage/*', 'themes/*', 'inserts/*', 'booklet/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $away = Upkeep::readersAway();
        if ($away === null || $request->is(...self::OPEN) || $request->user() !== null) {
            return $next($request);
        }

        $retry = ! empty($away['until']) ? max(60, (int) now()->diffInSeconds($away['until'], false)) : 600;

        return response()->view('errors.503', ['away' => $away], 503)
            ->header('Retry-After', (string) $retry)
            ->header('Cache-Control', 'no-store');
    }
}
