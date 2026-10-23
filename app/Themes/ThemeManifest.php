<?php

namespace App\Themes;

use App\Support\Look;
use Illuminate\Support\Str;

/**
 * What a theme says about itself in its theme.json, read as plain data
 * (nothing runs) and tidied: anything unknown or badly formed is left out,
 * and each thing left out is named in "problems" so the person adding it,
 * or the person making it, can see why.
 */
class ThemeManifest
{
    public const KEY = '/^[a-z][a-z0-9-]{0,59}$/';

    public const HANDLE = '/^[a-z][a-z0-9_-]{0,39}$/';

    public const HEX = '/^#[0-9a-f]{6}$/i';

    /** A Google Fonts family, as Google writes it ("Recoleta", "DM+Serif+Text:ital@0;1"). */
    public const GOOGLE = '/^[A-Za-z0-9+ ]{1,60}(:[a-z,]+@[0-9.;,]{1,80})?$/';

    public const OPTION_TYPES = ['toggle', 'select', 'color'];

    /**
     * @return array{manifest: array, problems: list<string>}
     */
    public static function read(string $json, string $fallbackKey = ''): array
    {
        $problems = [];
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return ['manifest' => [], 'problems' => ['theme.json isn’t valid JSON'.(json_last_error() ? ' ('.json_last_error_msg().')' : '').'.']];
        }

        $text = fn (string $k, int $max = 200) => is_scalar($data[$k] ?? null) ? Str::limit(trim(strip_tags((string) $data[$k])), $max, '') : '';

        $key = strtolower($text('key', 60));
        if (! preg_match(self::KEY, $key)) {
            $key = preg_match(self::KEY, $fallbackKey) ? $fallbackKey : Str::slug($text('name', 60));
        }

        $m = [
            'key' => $key,
            'name' => $text('name', 60) ?: Str::headline($key),
            'about' => $text('about', 300),
            'version' => $text('version', 20) ?: '1.0',
            'author' => $text('author', 80) ?: null,
            'homepage' => preg_match('#^https?://#i', $text('homepage', 300)) ? $text('homepage', 300) : null,
            'needs' => $text('needs', 20) ?: null,
            'preview' => self::file($data['preview'] ?? null),
            'booklet_css' => ! array_key_exists('booklet_css', $data) || (bool) $data['booklet_css'],
            'css' => [],
            'js' => [],
            'fonts' => [],
            'papers' => [],
            'presets' => [],
            'options' => [],
        ];

        foreach (['css', 'js'] as $kind) {
            foreach ((array) ($data[$kind] ?? []) as $file) {
                $clean = self::file($file);
                if ($clean && str_ends_with(strtolower($clean), '.'.$kind)) {
                    $m[$kind][] = $clean;
                } else {
                    $problems[] = 'Left out a '.$kind.' file that isn’t a .'.$kind.' in assets/ ('.Str::limit((string) json_encode($file), 60).').';
                }
            }
        }

        foreach ((array) ($data['fonts'] ?? []) as $handle => $font) {
            if (! preg_match(self::HANDLE, (string) $handle) || ! is_array($font) || ! is_string($font['name'] ?? null)) {
                $problems[] = 'Left out a font without a handle and a name ('.Str::limit((string) $handle, 40).').';

                continue;
            }
            $stack = (string) ($font['stack'] ?? "'".str_replace("'", '', $font['name'])."', serif");
            if (! preg_match('/^[A-Za-z0-9 ,\'-]{1,200}$/', $stack)) {
                $problems[] = 'Left out the font '.$handle.': its stack can only list font names.';

                continue;
            }
            $google = isset($font['google']) ? (string) $font['google'] : null;
            if ($google !== null && ! preg_match(self::GOOGLE, $google)) {
                $problems[] = 'The font '.$handle.' has a Google Fonts name Booklet can’t use; bundle the font in the theme’s CSS instead.';
                $google = null;
            }
            $use = array_values(array_intersect((array) ($font['use'] ?? ['titles', 'labels']), ['titles', 'labels']));
            $m['fonts']['theme-'.$handle] = [Str::limit(strip_tags($font['name']), 40, ''), $google ? str_replace(' ', '+', $google) : null, $stack, $use ?: ['titles', 'labels']];
        }

        foreach ((array) ($data['papers'] ?? []) as $handle => $paper) {
            $colours = is_array($paper) ? [$paper['paper'] ?? null, $paper['paper_2'] ?? null, $paper['rule'] ?? null, $paper['rule_2'] ?? null, $paper['ink'] ?? null] : [];
            if (! preg_match(self::HANDLE, (string) $handle) || count(array_filter($colours, fn ($c) => is_string($c) && preg_match(self::HEX, $c))) !== 5) {
                $problems[] = 'Left out the paper '.Str::limit((string) $handle, 40).': it needs paper, paper_2, rule, rule_2 and ink, each like #FBF8F1.';

                continue;
            }
            $m['papers']['theme-'.$handle] = [Str::limit(strip_tags((string) ($paper['name'] ?? Str::headline((string) $handle))), 40, ''), ...array_map('strtoupper', $colours)];
        }

        foreach ((array) ($data['options'] ?? []) as $handle => $option) {
            $type = is_array($option) ? ($option['type'] ?? null) : null;
            if (! preg_match(self::HANDLE, (string) $handle) || ! in_array($type, self::OPTION_TYPES, true)) {
                $problems[] = 'Left out the option '.Str::limit((string) $handle, 40).': its type should be toggle, select or color.';

                continue;
            }
            $o = ['type' => $type, 'label' => Str::limit(strip_tags((string) ($option['label'] ?? Str::headline((string) $handle))), 60, ''), 'hint' => Str::limit(strip_tags((string) ($option['hint'] ?? '')), 160, '')];
            if ($type === 'toggle') {
                $o['default'] = (bool) ($option['default'] ?? false);
                $o['class'] = self::cssClass($option['class'] ?? $handle) ?? self::cssClass((string) $handle) ?? 'option-'.$handle;
            } elseif ($type === 'select') {
                $choices = [];
                foreach ((array) ($option['options'] ?? []) as $value => $label) {
                    if (preg_match(self::HANDLE, (string) $value)) {
                        $choices[(string) $value] = Str::limit(strip_tags((string) $label), 40, '');
                    }
                }
                if ($choices === []) {
                    $problems[] = 'Left out the option '.$handle.': a select needs options, like {"none": "None"}.';

                    continue;
                }
                $o['options'] = $choices;
                $o['default'] = array_key_exists((string) ($option['default'] ?? ''), $choices) ? (string) $option['default'] : array_key_first($choices);
                $o['class'] = str_contains((string) ($option['class'] ?? ''), '{value}') ? (string) $option['class'] : $handle.'-{value}';
                if (! self::cssClass(str_replace('{value}', 'x', $o['class']))) {
                    $o['class'] = $handle.'-{value}';
                }
            } else {
                $o['default'] = preg_match(self::HEX, (string) ($option['default'] ?? '')) ? strtoupper((string) $option['default']) : '#1E1C18';
                $o['variable'] = '--'.(preg_match('/^[a-z][a-z0-9-]{0,40}$/', ltrim((string) ($option['variable'] ?? $handle), '-')) ? ltrim((string) ($option['variable'] ?? $handle), '-') : 'theme-'.$handle);
            }
            $m['options'][(string) $handle] = $o;
        }

        foreach ((array) ($data['presets'] ?? []) as $handle => $preset) {
            if (! preg_match(self::HANDLE, (string) $handle) || ! is_array($preset)) {
                $problems[] = 'Left out a preset without a handle ('.Str::limit((string) $handle, 40).').';

                continue;
            }
            $values = ['name' => Str::limit(strip_tags((string) ($preset['name'] ?? Str::headline((string) $handle))), 40, ''), 'about' => Str::limit(strip_tags((string) ($preset['about'] ?? '')), 160, '')];
            foreach (['title_font', 'label_font', 'paper'] as $field) {
                if (isset($preset[$field]) && is_string($preset[$field])) {
                    // A theme's own font or paper is named by its handle; Booklet's by theirs.
                    $values[$field] = isset($m[$field === 'paper' ? 'papers' : 'fonts']['theme-'.$preset[$field]]) ? 'theme-'.$preset[$field] : $preset[$field];
                }
            }
            if (isset($preset['accent']) && is_string($preset['accent']) && preg_match(self::HEX, $preset['accent'])) {
                $values['accent'] = strtoupper($preset['accent']);
            }
            foreach (['header' => Look::HEADERS, 'home' => Look::HOMES, 'corners' => Look::CORNERS] as $field => $allowed) {
                if (isset($preset[$field]) && is_string($preset[$field]) && array_key_exists($preset[$field], $allowed)) {
                    $values[$field] = $preset[$field];
                }
            }
            if (array_key_exists('tilt', $preset)) {
                $values['tilt'] = (bool) $preset['tilt'];
            }
            $values['options'] = is_array($preset['options'] ?? null) ? $preset['options'] : [];
            $m['presets'][(string) $handle] = $values;
        }

        return ['manifest' => $m, 'problems' => $problems];
    }

    /** A file in the theme's assets/ folder: no way out of it. */
    public static function file(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        $parts = explode('/', $path);
        if ($path === '' || in_array('..', $parts, true) || in_array('', $parts, true) || ! preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $path)) {
            return null;
        }

        return $path;
    }

    protected static function cssClass(mixed $class): ?string
    {
        $class = is_string($class) ? strtolower(trim($class)) : '';

        return preg_match('/^[a-z][a-z0-9_-]{0,60}$/', $class) ? $class : null;
    }

    /** The Booklet values a preset leaves out, so every preset is a whole look. */
    public static function fillPreset(array $preset): array
    {
        return $preset + array_diff_key(Look::PRESETS['notebook'], ['name' => 1, 'about' => 1]) + ['options' => []];
    }
}
