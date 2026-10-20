<?php

namespace App\Support;

use App\Models\Redirect;
use Illuminate\Http\Request;

/**
 * Old addresses that forward to new ones. Made automatically when a live
 * entry's address changes (a new slug, a new parent page, a new date), and by
 * hand on the Redirects page. Never chained: when an address moves again,
 * every old redirect that pointed at it points straight to the newest one.
 * Only used when nothing lives at the address, so a real page always wins.
 */
class Redirects
{
    /** "/blog/hello/" and "blog/hello" are the same address. Query strings aren't part of it. */
    public static function normalise(?string $path): string
    {
        $path = (string) parse_url(trim((string) $path), PHP_URL_PATH);

        return '/'.trim(preg_replace('#/+#', '/', $path), '/');
    }

    /** A live entry's address changed from $from to $to. */
    public static function moved(string $from, string $to, ?string $entryId = null): void
    {
        $from = self::normalise($from);
        $to = self::normalise($to);

        if ($from === $to || $from === '/') {
            return;
        }

        // The new address is live now: nothing should forward away from it.
        Redirect::where('from_path', $to)->delete();

        // No chains: whatever pointed at the old address now points at the new one.
        Redirect::where('to_path', $from)->update(['to_path' => $to, 'updated_at' => now()]);

        Redirect::updateOrCreate(
            ['from_path' => $from],
            ['to_path' => $to, 'status' => 301, 'entry_id' => $entryId, 'automatic' => true],
        );
    }

    /** Where should this request go instead? Null if nowhere. */
    public static function for(Request $request): ?Redirect
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $redirect = rescue(fn () => Redirect::where('from_path', self::normalise($request->path()))->first(), null, false);

        if ($redirect && self::normalise($redirect->to_path) !== self::normalise($request->path())) {
            $redirect->forceFill(['hits' => $redirect->hits + 1, 'last_hit_at' => now()])->saveQuietly();

            return $redirect;
        }

        return null;
    }

    /** The full address to send someone to, keeping any ?query. */
    public static function target(Redirect $redirect, Request $request): string
    {
        $to = $redirect->to_path;
        if (! preg_match('#^https?://#i', $to)) {
            $to = url($to);
        }

        $query = $request->getQueryString();

        return $query ? $to.(str_contains($to, '?') ? '&' : '?').$query : $to;
    }
}
