<?php

namespace App\Support;

use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Themes\Theme;
use App\Themes\ThemeManager;
use Illuminate\Support\Facades\Cache;

/**
 * The site's look ("the cover"): fonts, the accent colour, the paper, how
 * the header and the home page are laid out, corners and stickers. Chosen
 * from a few presets and tweaked in Settings › The cover, with a live
 * preview; no CSS needed. Kept in the site's settings as "look".
 */
class Look
{
    /** handle => [name, Google Fonts family (with weights), CSS stack] */
    public const FONTS = [
        'newsreader' => ['Newsreader', 'Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400', "'Newsreader', Georgia, serif"],
        'fraunces' => ['Fraunces', 'Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400', "'Fraunces', Georgia, serif"],
        'playfair' => ['Playfair Display', 'Playfair+Display:ital,wght@0,400;0,600;1,400', "'Playfair Display', Georgia, serif"],
        'lora' => ['Lora', 'Lora:ital,wght@0,400;0,600;1,400', "'Lora', Georgia, serif"],
        'dm-serif' => ['DM Serif Text', 'DM+Serif+Text:ital@0;1', "'DM Serif Text', Georgia, serif"],
        'instrument' => ['Instrument Sans', 'Instrument+Sans:wght@400;500;600', "'Instrument Sans', system-ui, sans-serif"],
        'inter' => ['Inter', 'Inter:wght@400;500;600', "'Inter', system-ui, sans-serif"],
        'work' => ['Work Sans', 'Work+Sans:wght@400;500;600', "'Work Sans', system-ui, sans-serif"],
        'source' => ['Source Sans 3', 'Source+Sans+3:wght@400;600', "'Source Sans 3', system-ui, sans-serif"],
        'space' => ['Space Grotesk', 'Space+Grotesk:wght@400;500;600', "'Space Grotesk', system-ui, sans-serif"],
    ];

    /** Fonts for titles and reading (serif and sans both welcome), and for menus and labels. */
    public const TITLE_FONTS = ['newsreader', 'fraunces', 'playfair', 'lora', 'dm-serif', 'inter', 'space'];

    public const LABEL_FONTS = ['instrument', 'inter', 'work', 'source', 'space'];

    /** Paper: name and its light-mode colours (paper, paper-2, rule, rule-2, ink). */
    public const PAPERS = [
        'warm' => ['Warm paper', '#FBF8F1', '#EFE9DC', '#E4DDCE', '#CFC6B3', '#1E1C18'],
        'white' => ['Bright white', '#FFFFFF', '#F3F3F1', '#E6E5E1', '#CFCDC6', '#16171A'],
        'grey' => ['Soft grey', '#F4F4F2', '#E8E8E4', '#DCDCD6', '#C5C5BD', '#1B1C1E'],
        'blush' => ['Blush', '#FCF5F3', '#F3E6E2', '#EAD8D2', '#D9C2BA', '#231B19'],
    ];

    public const HEADERS = [
        'classic' => ['Classic', 'Name on the left, menu on the right.'],
        'centered' => ['Centred', 'Name in the middle, the menu underneath.'],
        'banner' => ['Banner', 'A big name and tagline, the menu below.'],
    ];

    public const HOMES = [
        'feature' => ['Featured story', 'The newest post large, the rest as cards.'],
        'grid' => ['Grid', 'Every post as a card, side by side.'],
        'list' => ['List', 'Titles and first lines, no pictures. Quick to scan.'],
    ];

    public const CORNERS = ['soft' => 'Soft', 'sharp' => 'Sharp'];

    /** The starting points. Each is a whole look; anything can be changed after. */
    public const PRESETS = [
        'notebook' => ['name' => 'Notebook', 'about' => 'Warm paper, a bookish serif, tilted stickers. Booklet’s own.',
            'title_font' => 'newsreader', 'label_font' => 'instrument', 'accent' => '#F4876A', 'paper' => 'warm', 'header' => 'classic', 'home' => 'feature', 'corners' => 'soft', 'tilt' => true],
        'broadsheet' => ['name' => 'Broadsheet', 'about' => 'A newspaper: white page, high-contrast serif, straight lines.',
            'title_font' => 'playfair', 'label_font' => 'source', 'accent' => '#C0392B', 'paper' => 'white', 'header' => 'centered', 'home' => 'list', 'corners' => 'sharp', 'tilt' => false],
        'kopitiam' => ['name' => 'Kopitiam', 'about' => 'Coffee-shop warmth: brown and cream, friendly and round.',
            'title_font' => 'dm-serif', 'label_font' => 'work', 'accent' => '#C27C4A', 'paper' => 'warm', 'header' => 'banner', 'home' => 'grid', 'corners' => 'soft', 'tilt' => true],
        'botanic' => ['name' => 'Botanic', 'about' => 'Garden green on blush, a soft modern serif.',
            'title_font' => 'fraunces', 'label_font' => 'inter', 'accent' => '#4F8A6B', 'paper' => 'blush', 'header' => 'centered', 'home' => 'feature', 'corners' => 'soft', 'tilt' => false],
        'studio' => ['name' => 'Studio', 'about' => 'Clean and modern: one sans font, grey paper, sharp corners.',
            'title_font' => 'space', 'label_font' => 'space', 'accent' => '#3B5BDB', 'paper' => 'grey', 'header' => 'classic', 'home' => 'grid', 'corners' => 'sharp', 'tilt' => false],
    ];

    public const PREVIEW = 'booklet.look_preview.';

    /** The look in use: saved, or Booklet's own, and the person's unsaved one on their preview. */
    public static function current(): array
    {
        if (($preview = self::previewing()) !== null) {
            return $preview;
        }
        $saved = rescue(fn () => (array) (Booklet::globals('site')?->get('look') ?? []), [], false);

        return self::clean($saved + ['accent' => (string) (Booklet::globals('site')?->get('accent') ?? self::PRESETS['notebook']['accent'])]);
    }

    // With a theme: its presets, fonts, papers and options beside Booklet's -----------------

    /** The theme a look wears (null: Booklet's own). */
    public static function theme(array $look): ?Theme
    {
        return ThemeManager::forLook($look);
    }

    /** The presets to start from: Booklet's five, or the theme's own. */
    public static function presets(?Theme $theme): array
    {
        return $theme ? $theme->presets() : self::PRESETS;
    }

    /** handle => [name, Google Fonts family (or null: the theme brings it), CSS stack] */
    public static function fonts(?Theme $theme): array
    {
        $theirs = array_map(fn ($f) => [$f[0], $f[1], $f[2]], $theme?->fonts() ?? []);

        return $theirs + self::FONTS;
    }

    public static function titleFonts(?Theme $theme): array
    {
        return array_merge(array_keys(array_filter($theme?->fonts() ?? [], fn ($f) => in_array('titles', $f[3], true))), self::TITLE_FONTS);
    }

    public static function labelFonts(?Theme $theme): array
    {
        return array_merge(array_keys(array_filter($theme?->fonts() ?? [], fn ($f) => in_array('labels', $f[3], true))), self::LABEL_FONTS);
    }

    public static function papers(?Theme $theme): array
    {
        return ($theme?->papers() ?? []) + self::PAPERS;
    }

    /** What to call a look: its preset's name (and its theme's, when it isn't Booklet's). */
    public static function presetName(array $look): string
    {
        $theme = self::theme($look);
        $preset = self::presets($theme)[$look['preset'] ?? ''] ?? null;
        $name = (string) ($preset['name'] ?? 'Notebook');

        return $theme && $name !== $theme->name() ? $theme->name().' · '.$name : $name;
    }

    /** Anything missing or unknown becomes the preset's (and an unknown preset, the first one). */
    public static function clean(array $look): array
    {
        // Only plain values count (a hand-edited settings file or a tampered request can send anything).
        $look = array_map(fn ($v) => is_scalar($v) || $v === null ? $v : null, array_diff_key($look, ['options' => 1]))
            + ['options' => is_array($look['options'] ?? null) ? array_filter($look['options'], fn ($v) => is_scalar($v)) : []];
        $theme = self::theme($look);
        $presets = self::presets($theme);
        $preset = array_key_exists((string) ($look['preset'] ?? ''), $presets) ? (string) $look['preset'] : (string) array_key_first($presets);
        $base = $presets[$preset];
        $pick = function (string $key, array $allowed) use ($look, $base) {
            if (in_array($look[$key] ?? null, $allowed, true)) {
                return $look[$key];
            }

            return in_array($base[$key] ?? null, $allowed, true) ? $base[$key] : self::PRESETS['notebook'][$key];
        };
        $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtoupper($v) : null;

        // A theme's options: what the person chose, or the preset's, or the theme's default.
        $options = [];
        foreach ($theme?->options() ?? [] as $handle => $option) {
            $value = $look['options'][$handle] ?? $base['options'][$handle] ?? $option['default'];
            $options[$handle] = match ($option['type']) {
                'toggle' => (bool) $value,
                'select' => array_key_exists((string) $value, $option['options']) ? (string) $value : $option['default'],
                default => $hex($value) ?? $option['default'],
            };
        }

        return [
            'theme' => $theme ? $theme->key : 'booklet',
            'preset' => $preset,
            'title_font' => $pick('title_font', self::titleFonts($theme)),
            'label_font' => $pick('label_font', self::labelFonts($theme)),
            'accent' => $hex($look['accent'] ?? null) ?? $hex($base['accent'] ?? null) ?? self::PRESETS['notebook']['accent'],
            'paper' => $pick('paper', array_keys(self::papers($theme))),
            'header' => $pick('header', array_keys(self::HEADERS)),
            'home' => $pick('home', array_keys(self::HOMES)),
            'corners' => $pick('corners', array_keys(self::CORNERS)),
            'tilt' => isset($look['tilt']) ? (bool) $look['tilt'] : (bool) ($base['tilt'] ?? true),
            'options' => $options,
        ];
    }

    public static function save(array $look): void
    {
        $look = self::clean($look);
        $site = config('booklet.default_site');
        if (! Booklet::globalSets()->find('site')) {
            Booklet::globalSets()->make(['handle' => 'site', 'title' => 'Site'])->save();
        }
        $globals = Booklet::globals('site') ?? Booklet::globalVariables()->make(['handle' => 'site', 'locale' => $site]);
        // The accent lives where the rest of Booklet (emails, share cards) already looks for it.
        $globals->merge(['look' => $look, 'accent' => $look['accent']])->save();
        PageCache::flush();
    }

    // The unsaved look, for the preview -------------------------------------------------

    public static function previewKey(int|string $userId): string
    {
        return self::PREVIEW.$userId;
    }

    public static function previewing(): ?array
    {
        $request = request();
        $user = $request->user();
        if (! $request->query->has('look_preview') || ! $user instanceof User || ! $user->isAdmin()) {
            return null;
        }
        $look = Cache::get(self::previewKey($user->getAuthIdentifier()));

        return is_array($look) ? self::clean($look) : null;
    }

    // Into the page ---------------------------------------------------------------------

    /** The Google Fonts address for the chosen fonts (and the mono one for code). */
    public static function fontsUrl(array $look): string
    {
        $fonts = self::fonts(self::theme($look));
        // A theme's own fonts come with its styles; only Google's are asked for here.
        $families = array_values(array_unique(array_filter([$fonts[$look['title_font']][1] ?? null, $fonts[$look['label_font']][1] ?? null, 'JetBrains+Mono:wght@400;500'])));

        return 'https://fonts.googleapis.com/css2?family='.implode('&family=', $families).'&display=swap';
    }

    /** CSS custom properties for the page: fonts, accent (and the text that sits on it), paper. */
    public static function css(array $look): string
    {
        $theme = self::theme($look);
        $fonts = self::fonts($theme);
        [, $paper, $paper2, $rule, $rule2, $ink] = self::papers($theme)[$look['paper']] ?? self::PAPERS['warm'];
        $vars = [
            '--serif' => $fonts[$look['title_font']][2] ?? self::FONTS['newsreader'][2],
            '--sans' => $fonts[$look['label_font']][2] ?? self::FONTS['instrument'][2],
            '--accent' => $look['accent'],
            '--on-accent' => self::onAccent($look['accent']),
        ];
        // A theme's colour options, as the variables its styles use.
        foreach ($theme?->options() ?? [] as $handle => $option) {
            if ($option['type'] === 'color' && isset($look['options'][$handle])) {
                $vars[$option['variable']] = $look['options'][$handle];
            }
        }
        $light = ['--paper' => $paper, '--paper-2' => $paper2, '--rule' => $rule, '--rule-2' => $rule2, '--ink' => $ink];

        $line = fn (array $props) => implode(' ', array_map(fn ($k, $v) => $k.': '.$v.';', array_keys($props), $props));

        // The paper applies to light reading only; dark reading keeps its own.
        return ':root { '.$line($vars).' } :root:not([data-theme="dark"]) { '.$line($light).' }';
    }

    /** Classes for <body>: the header and home layouts, corners, stickers. */
    public static function bodyClasses(array $look): string
    {
        $classes = [
            'look-header--'.$look['header'],
            'look-home--'.$look['home'],
            'look-corners--'.$look['corners'],
            $look['tilt'] ? 'look-tilt' : 'look-flat',
        ];
        // A theme's name, and its options as the classes its styles look for.
        if ($theme = self::theme($look)) {
            $classes[] = 'theme-'.$theme->key;
            foreach ($theme->options() as $handle => $option) {
                $value = $look['options'][$handle] ?? $option['default'];
                if ($option['type'] === 'toggle' && $value) {
                    $classes[] = $option['class'];
                } elseif ($option['type'] === 'select') {
                    $classes[] = str_replace('{value}', (string) $value, $option['class']);
                }
            }
        }

        return implode(' ', $classes);
    }

    /** Dark text on a light accent, white on a dark one (so stickers stay readable). */
    public static function onAccent(string $hex): string
    {
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
        $lin = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);

        // Contrast against #1E1C18 vs white: pick the better one.
        $dark = ($l + 0.05) / (0.0130 + 0.05);
        $white = 1.05 / ($l + 0.05);

        return $dark >= $white ? '#1E1C18' : '#FFFFFF';
    }
}
