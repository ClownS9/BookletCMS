<?php

namespace App\Support;

use App\Models\User;

/**
 * How Booklet looks and opens for one person ("Make it yours"): the layout
 * (the book, or a plain dashboard), the theme, the fonts, spacing, text size
 * and page turning. Kept in the person's own preferences, so everyone has
 * their own and nobody else's changes.
 */
class YourBook
{
    public const LAYOUTS = [
        'book' => ['Book', 'Two pages, the stickers along the top, the desk around it.'],
        'dashboard' => ['Dashboard', 'A plain CMS desk: sidebar, panels and tables. No cover, no page turning.'],
    ];

    public const THEMES = [
        'paper' => ['Paper', '#3A3730', '#FBF8F1', '#1E1C18', '#6B655A'],
        'linen' => ['Linen', '#3E4A40', '#F6F5EE', '#1B201C', '#5E685B'],
        'mist' => ['Mist', '#34405A', '#F5F7FA', '#172033', '#566076'],
        'rose' => ['Rose', '#5B3A42', '#FCF6F3', '#2A1A1D', '#72565B'],
    ];

    /** name, about, heading stack, text stack, Google Fonts families (null: already loaded) */
    public const FONTS = [
        'booklet' => ['Booklet', 'As it comes', "'Newsreader', Georgia, serif", "'Instrument Sans', system-ui, sans-serif", null],
        'classic' => ['Classic', 'Bookish and warm', "'Lora', Georgia, serif", "'Source Sans 3', system-ui, sans-serif", 'family=Lora:ital,wght@0,400;0,500;1,400&family=Source+Sans+3:wght@400;500;600;700'],
        'plain' => ['Plain', 'One clean sans', "'Instrument Sans', system-ui, sans-serif", "'Instrument Sans', system-ui, sans-serif", null],
        'plex' => ['Plex', 'Crisp and technical', "'IBM Plex Serif', Georgia, serif", "'IBM Plex Sans', system-ui, sans-serif", 'family=IBM+Plex+Serif:ital,wght@0,400;0,500;1,400&family=IBM+Plex+Sans:wght@400;500;600;700'],
        'friendly' => ['Friendly', 'Soft and round', "'Fraunces', Georgia, serif", "'Nunito', system-ui, sans-serif", 'family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;1,9..144,400&family=Nunito:wght@400;500;600;700'],
        'easy' => ['Easy reading', 'Clearest letters', "'Atkinson Hyperlegible', system-ui, sans-serif", "'Atkinson Hyperlegible', system-ui, sans-serif", 'family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400'],
    ];

    public const DENSITIES = ['roomy' => 'Roomy', 'snug' => 'Snug'];

    public const SIZES = ['s' => 'Smaller text', 'm' => 'Usual text', 'l' => 'Larger text'];

    public const DEFAULTS = [
        'layout' => 'book',
        'theme' => 'paper',
        'font' => 'booklet',
        'density' => 'roomy',
        'size' => 'm',
        'turn' => true,
        'night_auto' => false,
    ];

    /** @var array<int, array<string, mixed>> */
    protected static array $memo = [];

    /**
     * This person's choices, with Booklet's own for anything not chosen (or
     * no longer offered).
     *
     * @return array{layout: string, theme: string, font: string, density: string, size: string, turn: bool, night_auto: bool}
     */
    public static function of(?User $user): array
    {
        if (! $user) {
            return self::DEFAULTS;
        }

        return self::$memo[$user->id.':'.md5(json_encode($user->preferences['yours'] ?? null))] ??= self::clean((array) ($user->preferences['yours'] ?? []));
    }

    /** Is the person signed in now using the Dashboard layout? */
    public static function desk(): bool
    {
        return self::of(request()->user())['layout'] === 'dashboard';
    }

    /**
     * Only known values are kept; anything else falls back to the default.
     *
     * @param  array<string, mixed>  $in
     * @return array{layout: string, theme: string, font: string, density: string, size: string, turn: bool, night_auto: bool}
     */
    public static function clean(array $in): array
    {
        $pick = fn (string $key, array $allowed) => is_string($in[$key] ?? null) && array_key_exists($in[$key], $allowed) ? $in[$key] : self::DEFAULTS[$key];

        return [
            'layout' => $pick('layout', self::LAYOUTS),
            'theme' => $pick('theme', self::THEMES),
            'font' => $pick('font', self::FONTS),
            'density' => $pick('density', self::DENSITIES),
            'size' => $pick('size', self::SIZES),
            'turn' => array_key_exists('turn', $in) ? (bool) $in['turn'] : self::DEFAULTS['turn'],
            'night_auto' => array_key_exists('night_auto', $in) ? (bool) $in['night_auto'] : self::DEFAULTS['night_auto'],
        ];
    }

    /** @param  array<string, mixed>  $changes */
    public static function save(User $user, array $changes): void
    {
        $chosen = self::clean(array_merge(self::of($user), $changes));
        // Only what differs from Booklet's own is kept, so new defaults reach everyone else.
        $kept = array_filter($chosen, fn ($value, $key) => $value !== self::DEFAULTS[$key], ARRAY_FILTER_USE_BOTH);

        $preferences = (array) $user->preferences;
        if ($kept === []) {
            unset($preferences['yours']);
        } else {
            $preferences['yours'] = $kept;
        }
        $user->forceFill(['preferences' => $preferences])->save();
    }

    public static function reset(User $user): void
    {
        $preferences = (array) $user->preferences;
        unset($preferences['yours']);
        $user->forceFill(['preferences' => $preferences])->save();
    }

    public static function dashboard(?User $user): bool
    {
        return self::of($user)['layout'] === 'dashboard';
    }

    /**
     * Classes for the page's body: layout-*, theme-*, font-*, density and size.
     *
     * @return array<string, bool>
     */
    public static function bodyClasses(?User $user): array
    {
        $look = self::of($user);

        return [
            'layout-'.$look['layout'] => true,
            'theme-'.$look['theme'] => $look['theme'] !== 'paper',
            'font-'.$look['font'] => $look['font'] !== 'booklet',
            'density-snug' => $look['density'] === 'snug',
            'text-'.$look['size'] => $look['size'] !== 'm',
            'no-turn' => ! $look['turn'],
        ];
    }

    /** The Google Fonts address for the chosen fonts, when they aren't Booklet's own. */
    public static function fontLink(?User $user): ?string
    {
        $families = self::FONTS[self::of($user)['font']][4];

        return $families ? 'https://fonts.googleapis.com/css2?'.$families.'&display=swap' : null;
    }

    /** The desk colour for the browser's own bar. */
    public static function deskColour(?User $user): string
    {
        return match (self::of($user)['theme']) {
            'linen' => '#1F2420',
            'mist' => '#1B2029',
            'rose' => '#271E20',
            default => '#23211D',
        };
    }
}
