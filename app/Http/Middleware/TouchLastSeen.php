<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps users.last_seen_at fresh for the online dots, writing at most once a minute.
 */
class TouchLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinute()))) {
            // Back after a while (a page opened, not a background update)? Stacy says welcome back.
            if ($user->last_seen_at?->lt(now()->subHours(\App\Stacy\Greeting::AWAY_HOURS))
                && $request->isMethod('GET') && ! $request->hasHeader('X-Livewire') && $request->hasSession()
                && ! $request->session()->has('stacy.greet')) {
                $request->session()->put('stacy.greet', 'back');
                $request->session()->put('stacy.away_since', $user->last_seen_at->toIso8601String());
            }

            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
