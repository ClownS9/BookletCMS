<?php

namespace App\Http\Middleware;

use App\Booklet\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Settings that change how every request behaves: the site's timezone
 * (when scheduled posts go out) and its language.
 */
class ApplySiteSettings
{
    /** The site's timezone: chosen in Settings, or APP_TIMEZONE in .env (null: leave UTC). */
    public static function timezone(): ?string
    {
        $chosen = (string) rescue(fn () => app(Site::class)->setting('timezone', ''), '', false);
        foreach ([$chosen, (string) config('booklet.timezone')] as $timezone) {
            if ($timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                return $timezone;
            }
        }

        return null;
    }

    /**
     * Use the site's timezone for dates and times. Web pages do this per
     * request; the scheduler and commands do it when they start, so "9:00"
     * means the same moment to both (scheduled posts, emails to subscribers).
     */
    public static function applyTimezone(): void
    {
        if ($timezone = self::timezone()) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $site = app(Site::class);
            $language = (string) $site->setting('language', '');
        } catch (Throwable) {
            // Before migrations have run there are no settings yet.
            return $next($request);
        }

        self::applyTimezone();

        if ($language !== '') {
            app()->setLocale($language);
        }

        return $next($request);
    }
}
