<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The words a script of Booklet's uses (the page builder's buttons and
 * messages), in the language the book speaks: sent with the page as
 * {English: translation}, only those the script has, so it can show them
 * without asking. English needs nothing.
 */
class ScriptWords
{
    /** @return array<string, string> */
    public static function for(string $script, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $file = public_path('booklet/'.basename($script));
        $lang = lang_path($locale.'.json');
        if ($locale === 'en' || ! is_file($file) || ! is_file($lang)) {
            return [];
        }

        return Cache::remember('booklet.script-words.'.md5($file.$locale.filemtime($file).filemtime($lang)), 86400, function () use ($file, $lang) {
            $js = (string) file_get_contents($file);
            $all = (array) json_decode((string) file_get_contents($lang), true);

            return array_filter($all, fn ($words, $english) => is_string($words) && $english !== '' && str_contains($js, (string) $english), ARRAY_FILTER_USE_BOTH);
        });
    }
}
