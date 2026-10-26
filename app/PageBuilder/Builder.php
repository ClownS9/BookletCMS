<?php

namespace App\PageBuilder;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The page builder's rules: making sections, and changing one field of one
 * section at a time (only fields its kind has, each checked for what it
 * may hold). Pages keep their sections as plain arrays:
 *
 *   ['id' => 'k3j9q2xa', 'type' => 'hero', 'fields' => [...], 'look' => [...]]
 */
class Builder
{
    public const MAX_SECTIONS = 40;

    /** @param  array{sections: array, looks: array, look: array, starters: array}  $kit  (sections.php) */
    public function __construct(public readonly array $kit) {}

    public function types(): array
    {
        return $this->kit['sections'];
    }

    public function type(string $type): ?array
    {
        return $this->kit['sections'][$type] ?? null;
    }

    public function looks(): array
    {
        return $this->kit['looks'];
    }

    public function starters(): array
    {
        return $this->kit['starters'];
    }

    // Making ------------------------------------------------------------------------------

    public function make(string $type): array
    {
        $kind = $this->type($type) ?? throw new InvalidArgumentException('There’s no section like that.');

        return [
            'id' => strtolower(Str::random(8)),
            'type' => $type,
            'fields' => $kind['start'] ?? [],
            'look' => array_merge($this->kit['look'], $kind['style'] ?? []),
        ];
    }

    /** @return list<array> */
    public function starter(string $key): array
    {
        $types = $this->kit['starters'][$key]['sections'] ?? $this->kit['starters']['blank']['sections'];

        return array_map(fn ($t) => $this->make($t), $types);
    }

    /** A copy with a new id (and the same words). */
    public function copy(array $section): array
    {
        return ['id' => strtolower(Str::random(8))] + $section;
    }

    /**
     * Sections as stored, made safe to draw: known kinds only, every look
     * filled in, ids present.
     *
     * @return list<array>
     */
    public function clean(mixed $sections): array
    {
        $out = [];
        foreach (is_array($sections) ? $sections : [] as $s) {
            if (! is_array($s) || ! is_string($s['type'] ?? null) || ! $this->type($s['type'])) {
                continue;
            }
            $look = array_merge($this->kit['look'], is_array($s['look'] ?? null) ? $s['look'] : []);
            foreach ($this->kit['looks'] as $key => $l) {
                if (! array_key_exists((string) $look[$key], $l['options'])) {
                    $look[$key] = $this->kit['look'][$key];
                }
            }
            $out[] = [
                'id' => is_string($s['id'] ?? null) && preg_match('/^[a-z0-9]{4,16}$/', $s['id']) ? $s['id'] : strtolower(Str::random(8)),
                'type' => $s['type'],
                'fields' => is_array($s['fields'] ?? null) ? $s['fields'] : [],
                'look' => $look,
            ];
        }

        return $out;
    }

    // Changing ------------------------------------------------------------------------------

    /**
     * What a field path names in a section kind: 'title', 'button.label',
     * 'items.2.words', 'items.0.button.url'…
     *
     * @return array{spec: array, kind: string}|null  kind: the field's type, or 'label' / 'href' for a link's two halves
     */
    public function field(string $type, string $path): ?array
    {
        $fields = $this->type($type)['fields'] ?? [];
        $parts = explode('.', $path);
        $name = array_shift($parts);
        $spec = $fields[$name] ?? null;
        if (! $spec) {
            return null;
        }
        if ($spec['type'] === 'items') {
            if (count($parts) === 0) {
                return ['spec' => $spec, 'kind' => 'items'];
            }
            $i = array_shift($parts);
            $sub = array_shift($parts);
            if (! ctype_digit((string) $i) || (int) $i >= (int) ($spec['max_items'] ?? 20) || ! isset($spec['fields'][$sub])) {
                return null;
            }
            $spec = $spec['fields'][$sub];
        }
        if ($spec['type'] === 'link') {
            $half = array_shift($parts);

            return in_array($half, ['label', 'url'], true) && $parts === [] ? ['spec' => $spec, 'kind' => $half === 'label' ? 'label' : 'href'] : null;
        }

        return $parts === [] ? ['spec' => $spec, 'kind' => $spec['type']] : null;
    }

    /**
     * Set one field. Throws InvalidArgumentException with words for the
     * person when the value can't go there.
     *
     * @param  callable(string): bool  $isPicture  whether a path is a picture in Media
     * @param  array<string, string>  $choices  the choices of a select whose options are worked out ('categories')
     */
    public function set(array $section, string $path, mixed $value, callable $isPicture, array $choices = []): array
    {
        $found = $this->field($section['type'], $path) ?? throw new InvalidArgumentException('That isn’t part of this section.');
        ['spec' => $spec, 'kind' => $kind] = $found;

        $value = match ($kind) {
            'text', 'label' => $this->line($value, (int) ($spec['max'] ?? ($kind === 'label' ? 60 : 200))),
            'words' => $this->words($value, (int) ($spec['max'] ?? 2000)),
            'href', 'url' => ! empty($spec['video']) ? $this->videoUrl($value) : $this->url($value),
            'select' => $this->choice($value, is_array($spec['options']) ? $spec['options'] : $choices),
            'toggle' => (bool) $value,
            'picture' => $this->picture($value, $isPicture),
            'pictures' => $this->pictures($value, $isPicture, (int) ($spec['max_items'] ?? 40)),
            default => throw new InvalidArgumentException('That can’t be set like that.'),
        };

        $fields = $section['fields'];
        data_set($fields, $path, $value);
        $section['fields'] = $fields;

        return $section;
    }

    public function setLook(array $section, string $key, mixed $value, callable $isPicture): array
    {
        if ($key === 'picture') {
            $section['look']['picture'] = $this->picture($value, $isPicture);
            if ($section['look']['picture'] !== '') {
                $section['look']['background'] = 'picture';
            }

            return $section;
        }
        $options = $this->kit['looks'][$key]['options'] ?? throw new InvalidArgumentException('That isn’t a look.');
        $section['look'][$key] = $this->choice($value, $options);

        return $section;
    }

    /** Add, move or take out one thing in a list field (cards, questions…). */
    public function items(array $section, string $name, string $do, int $at = 0, int $step = 0): array
    {
        $found = $this->field($section['type'], $name);
        if (! $found || $found['kind'] !== 'items') {
            throw new InvalidArgumentException('That isn’t a list.');
        }
        $spec = $found['spec'];
        $list = array_values(array_filter((array) ($section['fields'][$name] ?? []), 'is_array'));

        if ($do === 'add') {
            if (count($list) >= (int) ($spec['max_items'] ?? 20)) {
                throw new InvalidArgumentException('That’s as many as a section can hold.');
            }
            // A copy of the last one, so the new one looks right straight away.
            $new = $list !== [] ? end($list) : [];
            foreach ($spec['fields'] as $k => $f) {
                $new[$k] ??= match ($f['type']) {
                    'link' => ['label' => '', 'url' => ''], 'toggle' => false, default => '',
                };
            }
            if (array_key_exists('featured', $new)) {
                $new['featured'] = false;
            }
            array_splice($list, $at >= 0 && $at < count($list) ? $at + 1 : count($list), 0, [$new]);
        } elseif ($do === 'move') {
            if (! isset($list[$at], $list[$at + $step])) {
                return $section;
            }
            [$list[$at], $list[$at + $step]] = [$list[$at + $step], $list[$at]];
        } elseif ($do === 'remove') {
            unset($list[$at]);
            $list = array_values($list);
        }
        $section['fields'][$name] = $list;

        return $section;
    }

    // What fields may hold ------------------------------------------------------------------

    public function line(mixed $value, int $max = 200): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return Str::limit($value, $max, '');
    }

    public function words(mixed $value, int $max = 2000): string
    {
        $value = is_scalar($value) ? str_replace(["\r\n", "\r"], "\n", (string) $value) : '';
        $value = (string) preg_replace('/[^\S\n]+/u', ' ', $value);           // spaces, not tabs and odd blanks
        $value = (string) preg_replace("/ *\n */", "\n", $value);
        $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);           // a blank line at most

        return Str::limit(trim($value), $max, '');
    }

    /** An address a reader can be sent to: on this site, or http(s), mail, phone. Never javascript: and friends. */
    public function url(mixed $value): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) > 500 || preg_match('/[\s<>"]/', $value)) {
            throw new InvalidArgumentException('That doesn’t look like an address.');
        }
        if (preg_match('~^(#|/(?!/)|\?)~', $value) || preg_match('~^(https?://[^/\s]+|mailto:[^\s]+@|tel:\+?[0-9 ()-]+$)~i', $value)) {
            return $value;
        }
        if (preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)~i', $value)) {
            return 'https://'.$value; // example.com/page
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:'.$value;
        }

        throw new InvalidArgumentException('Use an address like /about, https://example.com, or an email address.');
    }

    public function videoUrl(mixed $value): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        if ($value !== '' && ! self::embed($value)) {
            throw new InvalidArgumentException('Paste a YouTube or Vimeo address (from the address bar, or Share).');
        }

        return $value;
    }

    /**
     * The address to show a video from: YouTube (without its tracking
     * cookies) or Vimeo, worked out from the ID only.
     */
    public static function embed(string $url): ?string
    {
        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1].'?rel=0';
        }
        if (preg_match('~^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{5,12})(?:/([0-9a-f]{6,20}))?~', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1].(! empty($m[2]) ? '?h='.$m[2].'&dnt=1' : '?dnt=1');
        }

        return null;
    }

    public function choice(mixed $value, array $options): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if (! array_key_exists($value, $options)) {
            throw new InvalidArgumentException('That isn’t one of the choices.');
        }

        return $value;
    }

    public function picture(mixed $value, callable $isPicture): string
    {
        $value = is_string($value) ? $value : '';
        if ($value !== '' && ! $isPicture($value)) {
            throw new InvalidArgumentException('That picture isn’t in Media.');
        }

        return $value;
    }

    public function pictures(mixed $value, callable $isPicture, int $max): array
    {
        $list = array_values(array_unique(array_filter((array) $value, fn ($p) => is_string($p) && $p !== '')));
        foreach ($list as $p) {
            $this->picture($p, $isPicture);
        }

        return array_slice($list, 0, $max);
    }

    // Addresses ------------------------------------------------------------------------------

    /** A page's address, as typed: "Our offer" → "our-offer", "/shop/spring" → "shop/spring". */
    public static function address(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $parts = array_filter(array_map(fn ($p) => Str::slug($p), explode('/', trim($value, " /\t\n"))));

        return Str::limit(implode('/', $parts), 120, '');
    }

    /** One line of what's on a section (for the list on the left). */
    public function summary(array $section): string
    {
        $f = $section['fields'];
        foreach (['title', 'eyebrow', 'words', 'intro', 'caption'] as $k) {
            if (is_string($f[$k] ?? null) && trim($f[$k]) !== '') {
                return Str::limit(trim($f[$k]), 48);
            }
        }
        foreach ((array) ($f['items'] ?? []) as $item) {
            foreach (['title', 'question', 'name', 'words'] as $k) {
                if (is_string($item[$k] ?? null) && trim($item[$k]) !== '') {
                    return Str::limit(trim($item[$k]), 48);
                }
            }
        }

        return '';
    }
}
