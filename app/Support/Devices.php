<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Where you're signed in: one line per browser, from the sessions table
 * (SESSION_DRIVER=database, the default). Signing a device out also stops
 * "Keep me signed in" from quietly letting it back in.
 */
class Devices
{
    public static function available(): bool
    {
        return config('session.driver') === 'database'
            && rescue(fn () => Schema::hasTable(self::table()), false, false);
    }

    protected static function table(): string
    {
        return (string) config('session.table', 'sessions');
    }

    /**
     * One row per signed-in device: id, browser, os, kind, ip, last (Carbon), current (bool).
     */
    public static function for(User $user): Collection
    {
        if (! self::available()) {
            return collect();
        }

        $current = session()->getId();

        return DB::table(self::table())
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->limit(30)
            ->get()
            ->map(fn ($s) => self::describe((string) $s->user_agent) + [
                'id' => (string) $s->id,
                'ip' => $s->ip_address,
                'last' => Carbon::createFromTimestamp((int) $s->last_activity),
                'current' => (string) $s->id === $current,
            ])
            ->sortByDesc('current')
            ->values();
    }

    /**
     * "Chrome on macOS", a computer.
     *
     * @return array{browser: string, os: string, kind: string}
     */
    public static function describe(string $agent): array
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'A browser',
        };
        $os = match (true) {
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'an unknown system',
        };
        $kind = match (true) {
            in_array($os, ['iPhone'], true) || (str_contains($agent, 'Android') && str_contains($agent, 'Mobile')) => 'phone',
            in_array($os, ['iPad', 'Android'], true) => 'tablet',
            default => 'computer',
        };

        return ['browser' => $browser, 'os' => $os, 'kind' => $kind];
    }

    /** Sign one device out (never the one you're using). */
    public static function signOut(User $user, string $id): bool
    {
        if ($id === session()->getId()) {
            return false;
        }

        $gone = DB::table(self::table())->where('user_id', $user->id)->where('id', $id)->delete() > 0;
        if ($gone) {
            self::forgetRemembered($user);
        }

        return $gone;
    }

    /** Sign out everywhere except here. Returns how many. */
    public static function signOutOthers(User $user): int
    {
        $n = self::available()
            ? DB::table(self::table())->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete()
            : 0;
        self::forgetRemembered($user);

        return $n;
    }

    /** After a password reset: every device signs in again, this one included. */
    public static function signOutEverywhere(User $user): int
    {
        $n = self::available() ? DB::table(self::table())->where('user_id', $user->id)->delete() : 0;
        self::forgetRemembered($user);

        return $n;
    }

    /**
     * "Keep me signed in" cookies on other devices stop working. If it's your
     * own account, this browser is given a fresh one so you stay signed in here.
     * (Never for someone else's account: an admin resetting a person must not
     * end up signed in as them.)
     */
    protected static function forgetRemembered(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        $guard = Auth::guard();
        $mine = $guard->check() && (string) $guard->id() === (string) $user->getAuthIdentifier();
        // Only a session guard ("keep me signed in" lives there) can hand this browser a fresh cookie.
        if ($mine && $guard instanceof \Illuminate\Auth\SessionGuard && request()->cookies->has($guard->getRecallerName())) {
            $guard->login($user, true);
        }
    }
}
