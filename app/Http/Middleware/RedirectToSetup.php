<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until the book has its first person, every page (the public site, the admin,
 * sign in) goes to First setup. Nothing is shown to visitors before then.
 */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPage($request) || static::isSetUp()) {
            return $next($request);
        }

        return redirect()->route('setup');
    }

    /**
     * Only real page visits are sent to setup: the public site, the admin, sign in.
     * Everything else (Livewire's script and its update requests, which setup itself
     * needs, uploads, the health check) is left alone, whatever its address looks like.
     */
    protected function isPage(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }
        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            return false;
        }

        // Livewire's own files and endpoints ("/livewire/…", or "/livewire-1a2b3c4d/…" in newer versions).
        if (preg_match('#^livewire(-[a-z0-9]+)?/#i', $request->path())) {
            return false;
        }

        $route = $request->route();
        if (! $route instanceof \Illuminate\Routing\Route) {
            return false;
        }
        if ($route->isFallback) {
            return true; // the public site's pages and posts
        }

        $name = (string) $route->getName();

        return $name !== 'setup'
            && (str_starts_with($name, 'site.') || str_starts_with($name, 'admin.')
                || in_array($name, ['login', 'preview', 'password.reset'], true));
    }

    /**
     * One tiny query (is there anyone at all?), asked fresh each request, so
     * `migrate:fresh` sends you straight back to setup with no cache to clear.
     */
    public static function isSetUp(): bool
    {
        try {
            return User::query()->exists();
        } catch (\Throwable) {
            return true; // no database yet (before migrating): don't get in the way
        }
    }
}
