<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The language the book (the admin) speaks: each person chooses theirs in
 * Your account; before signing in, the sign-in pages follow the last
 * choice made in this browser, or the browser's own language. Readers'
 * pages are left to the site: they speak its language (Settings), or a
 * translated post's own.
 *
 * Words are translated with Laravel's JSON files (lang/ms.json): the key is
 * the English, so anything not translated yet simply stays English.
 */
class BookLanguage
{
    /** code => [name in English, name in itself] */
    public const LANGUAGES = [
        'en' => ['English', 'English'],
        'ms' => ['Malay', 'Bahasa Melayu'],
    ];

    public const COOKIE = 'booklet_lang';

    /** Is this request for the book (or its sign-in pages), not a reader's page? */
    public static function isBook(Request $request): bool
    {
        $path = trim($request->path(), '/');
        // The page builder's drawer and its answers are the book's too (its preview is a reader's page).
        if (preg_match('#^(admin|login|logout|setup|forgot-password|reset-password|two-factor|account|builder/(edit|start|here))(/|$)#', $path)) {
            return true;
        }
        // A Livewire update: whichever page it came from.
        if (str_starts_with($path, 'livewire') && ($from = (string) parse_url((string) $request->headers->get('referer'), PHP_URL_PATH)) !== '') {
            return (bool) preg_match('#^/(admin|login|setup|forgot-password|reset-password|two-factor)(/|$)#', $from);
        }

        return false;
    }

    /** Which language this person, or this browser, reads the book in. */
    public static function for(Request $request): string
    {
        $chosen = (string) ($request->user()?->preferences['locale'] ?? $request->cookie(self::COOKIE) ?? '');
        if (isset(self::LANGUAGES[$chosen])) {
            return $chosen;
        }
        return self::fromBrowser($request);
    }

    /** The browser's own language, when the book speaks it (English otherwise). */
    public static function fromBrowser(Request $request): string
    {
        $browser = (string) $request->getPreferredLanguage(array_keys(self::LANGUAGES));
        $code = substr($browser, 0, 2);
        $default = (string) config('app.locale', 'en');

        return isset(self::LANGUAGES[$code]) ? $code : (isset(self::LANGUAGES[$default]) ? $default : 'en');
    }

    /** Speak it for the rest of this request (words, dates, "3 days ago"). */
    public static function use(string $code): void
    {
        if (! isset(self::LANGUAGES[$code])) {
            return;
        }
        app()->setLocale($code);
        Carbon::setLocale($code);
    }
}
