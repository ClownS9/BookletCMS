<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Inserts\Insert;
use App\Inserts\InsertManager;
use App\Inserts\ViewState;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Blocks: cut-outs tucked into a page (Statamic's Replicator). A "blocks"
 * field holds a list of them, stored the way Statamic stores sets:
 *
 *   - id: k3x9q2
 *     type: quote
 *     enabled: true
 *     quote: "Kopi first, then everything else."
 *     cite: Hana
 *
 * Booklet has its own kinds (quote, picture, button…), and inserts add more
 * with 'blocks' in insert.php; those are called "{insert}.{name}". A block
 * from an insert that's switched off is kept as it is and simply not drawn.
 */
class Blocks
{
    /** What a cut-out's own fields can be. */
    public const FIELD_TYPES = ['text', 'textarea', 'markdown', 'select', 'toggle', 'link', 'number', 'picture', 'pictures', 'entries'];

    public const MAX_BLOCKS = 60;

    public const MAX_PICTURES = 24;

    public const MAX_TEXT = 20000;

    /**
     * Booklet's own cut-outs.
     *
     * @return array<string, array{name: string, about: string, tone: string, fields: array<string, array>}>
     */
    public static function builtIn(): array
    {
        return [
            'text' => [
                'name' => 'Words', 'about' => 'More writing, between other cut-outs.', 'tone' => 'sand',
                'fields' => ['text' => ['type' => 'markdown', 'display' => 'Words']],
            ],
            'quote' => [
                'name' => 'Quote', 'about' => 'Something someone said, set large.', 'tone' => 'sun',
                'fields' => [
                    'quote' => ['type' => 'textarea', 'display' => 'Quote'],
                    'cite' => ['type' => 'text', 'display' => 'Who said it'],
                ],
            ],
            'picture' => [
                'name' => 'Picture', 'about' => 'One picture, with a caption.', 'tone' => 'sky',
                'fields' => [
                    'picture' => ['type' => 'picture', 'display' => 'Picture'],
                    'caption' => ['type' => 'text', 'display' => 'Caption'],
                    'size' => ['type' => 'select', 'display' => 'Size', 'default' => 'text',
                        'options' => ['text' => 'As wide as the words', 'wide' => 'Wider', 'full' => 'Edge to edge']],
                    'shape' => ['type' => 'select', 'display' => 'Shape', 'default' => '',
                        'options' => fn () => ['' => 'As it is'] + collect(Crops::sizes())->map(fn ($s) => $s['label'].' ('.Crops::ratio($s['width'], $s['height']).')')->all()],
                ],
            ],
            'gallery' => [
                'name' => 'Pictures', 'about' => 'A few pictures together.', 'tone' => 'mint',
                'fields' => [
                    'pictures' => ['type' => 'pictures', 'display' => 'Pictures'],
                    'caption' => ['type' => 'text', 'display' => 'Caption'],
                    'shape' => ['type' => 'select', 'display' => 'Shape', 'default' => 'square',
                        'options' => fn () => collect(Crops::sizes())->map(fn ($s) => $s['label'].' ('.Crops::ratio($s['width'], $s['height']).')')->all() + ['' => 'As they are']],
                ],
            ],
            'button' => [
                'name' => 'Call to action', 'about' => 'A short pitch and a button.', 'tone' => 'coral',
                'fields' => [
                    'heading' => ['type' => 'text', 'display' => 'Heading'],
                    'text' => ['type' => 'textarea', 'display' => 'Words'],
                    'label' => ['type' => 'text', 'display' => 'Button says', 'default' => 'Find out more'],
                    'link' => ['type' => 'link', 'display' => 'Goes to'],
                ],
            ],
            'columns' => [
                'name' => 'Two columns', 'about' => 'Words side by side.', 'tone' => 'lilac',
                'fields' => [
                    'left' => ['type' => 'markdown', 'display' => 'Left'],
                    'right' => ['type' => 'markdown', 'display' => 'Right'],
                ],
            ],
            'video' => [
                'name' => 'Video', 'about' => 'From YouTube or Vimeo.', 'tone' => 'rose',
                'fields' => [
                    'url' => ['type' => 'link', 'display' => 'Video link'],
                    'caption' => ['type' => 'text', 'display' => 'Caption'],
                ],
            ],
            'featured' => [
                'name' => 'Featured', 'about' => 'Posts or pages to read next, picked by hand.', 'tone' => 'coral',
                'fields' => [
                    'heading' => ['type' => 'text', 'display' => 'Heading', 'default' => 'You might also like'],
                    'entries' => ['type' => 'entries', 'display' => 'Posts or pages', 'max_items' => 6],
                ],
            ],
            'note' => [
                'name' => 'Note', 'about' => 'An aside in a box: a tip or a warning.', 'tone' => 'sun',
                'fields' => [
                    'kind' => ['type' => 'select', 'display' => 'Kind', 'default' => 'tip',
                        'options' => ['tip' => 'Tip', 'info' => 'Good to know', 'warning' => 'Careful']],
                    'text' => ['type' => 'markdown', 'display' => 'Words'],
                ],
            ],
            'break' => [
                'name' => 'Break', 'about' => 'A pause between parts.', 'tone' => 'sand',
                'fields' => ['style' => ['type' => 'select', 'display' => 'Looks like', 'default' => 'stars',
                    'options' => ['stars' => '· · ·', 'line' => 'A line', 'space' => 'Just space']]],
            ],
        ];
    }

    /**
     * Every kind on offer right now: Booklet's, then each switched-on insert's.
     *
     * @return array<string, array{name: string, about: string, tone: string, fields: array<string, array>, insert?: Insert, show?: callable}>
     */
    public static function kinds(): array
    {
        $kinds = self::builtIn();

        foreach (rescue(fn () => app(InsertManager::class)->active(), collect(), false) as $insert) {
            foreach (rescue(fn () => $insert->blocks(), [], false) as $name => $block) {
                $kinds[$insert->key.'.'.$name] = $block + ['insert' => $insert];
            }
        }

        return $kinds;
    }

    /**
     * The kinds a field offers ('sets' in its config narrows them).
     *
     * @return array<string, array>
     */
    public static function kindsFor(array $field): array
    {
        $kinds = self::kinds();
        $only = array_values(array_filter((array) ($field['sets'] ?? []), 'is_string'));

        return $only === [] ? $kinds : array_intersect_key($kinds, array_flip($only));
    }

    /** A new, empty block of a kind (or null for a kind that isn't on offer). */
    public static function make(string $type): ?array
    {
        $kind = self::kinds()[$type] ?? null;
        if (! $kind) {
            return null;
        }

        $block = ['id' => self::id(), 'type' => $type, 'enabled' => true];
        foreach ($kind['fields'] as $handle => $field) {
            $block[$handle] = self::blank($field);
        }

        return $block;
    }

    public static function id(): string
    {
        return Str::lower(Str::random(8));
    }

    protected static function blank(array $field): mixed
    {
        return match ($field['type']) {
            'toggle' => (bool) ($field['default'] ?? false),
            'picture', 'pictures', 'entries' => [],
            'select' => (string) ($field['default'] ?? array_key_first(self::options($field)) ?? ''),
            default => (string) ($field['default'] ?? ''),
        };
    }

    /** A select's choices ('options' may be worked out when asked, for inserts). */
    public static function options(array $field): array
    {
        $options = $field['options'] ?? [];
        if (is_callable($options)) {
            $options = rescue(fn () => $options(), [], false);
        }
        $options = (array) $options;

        return array_is_list($options) ? array_combine(array_map('strval', $options), array_map('strval', $options)) : $options;
    }

    // Cleaning ----------------------------------------------------------------------------------

    /**
     * Blocks from storage made ready for the editor: every field there, ids
     * given. Blocks of kinds not on offer are kept untouched.
     */
    public static function forEditor(mixed $stored): array
    {
        $kinds = self::kinds();
        $out = [];

        foreach (array_slice(is_array($stored) ? array_values($stored) : [], 0, self::MAX_BLOCKS) as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }
            $block['id'] = self::validId($block['id'] ?? null) ?? self::id();
            $block['enabled'] = (bool) ($block['enabled'] ?? true);

            foreach ($kinds[$block['type']]['fields'] ?? [] as $handle => $field) {
                $block[$handle] = array_key_exists($handle, $block) ? self::cleanValue($block[$handle], $field) : self::blank($field);
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * What the editor sent, made safe to keep: known kinds keep only their
     * own fields, each the right kind of value; unknown kinds stay as they were.
     */
    public static function clean(mixed $blocks): array
    {
        $kinds = self::kinds();
        $out = [];

        foreach (array_slice(is_array($blocks) ? array_values($blocks) : [], 0, self::MAX_BLOCKS) as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null) || ! preg_match('/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)?$/', $block['type'])) {
                continue;
            }

            $kind = $kinds[$block['type']] ?? null;
            if (! $kind) {
                $out[] = $block; // an insert's that's switched off: keep it for when it's back

                continue;
            }

            $clean = ['id' => self::validId($block['id'] ?? null) ?? self::id(), 'type' => $block['type'], 'enabled' => (bool) ($block['enabled'] ?? true)];
            foreach ($kind['fields'] as $handle => $field) {
                $value = self::cleanValue($block[$handle] ?? null, $field);
                if ($value !== '' && $value !== [] && $value !== null) {
                    $clean[$handle] = $value;
                }
            }
            $out[] = $clean;
        }

        return $out;
    }

    protected static function validId(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) ? $id : null;
    }

    protected static function cleanValue(mixed $value, array $field): mixed
    {
        $scalar = is_scalar($value) ? (string) $value : '';

        return match ($field['type']) {
            'toggle' => (bool) $value,
            'number' => is_numeric($value) ? $value + 0 : '',
            'select' => array_key_exists($scalar, self::options($field)) ? $scalar : (string) ($field['default'] ?? ''),
            'link' => self::safeLink($scalar) ?? '',
            'picture' => array_slice(self::paths($value), 0, 1),
            'pictures' => array_slice(self::paths($value), 0, self::MAX_PICTURES),
            'entries' => array_slice(array_values(array_unique(array_filter(is_array($value) ? $value : [], fn ($id) => Links::looksLikeId($id)))), 0, (int) ($field['max_items'] ?? 12) ?: 12),
            'text' => Str::limit(trim(str_replace(["\r", "\n"], ' ', $scalar)), 500, ''),
            default => mb_substr(str_replace("\r\n", "\n", $scalar), 0, self::MAX_TEXT),
        };
    }

    /** @return list<string> */
    protected static function paths(mixed $value): array
    {
        $paths = is_array($value) ? $value : (is_string($value) && $value !== '' ? [$value] : []);

        return array_values(array_unique(array_filter(array_map(
            fn ($p) => is_string($p) && $p !== '' && ! str_contains($p, '..') && ! str_starts_with($p, '/') ? $p : null,
            $paths,
        ))));
    }

    /** A link that's safe to put in a page: the web, mail, phone, or somewhere on this site. */
    public static function safeLink(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^(https?://|mailto:|tel:)#i', $url) || (str_starts_with($url, '/') && ! str_starts_with($url, '//')) || str_starts_with($url, '#')) {
            return Str::limit($url, 2000, '');
        }
        // "example.com/page" typed without the https://
        if (preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$#i', $url)) {
            return 'https://'.$url;
        }

        return null;
    }

    // Drawing -----------------------------------------------------------------------------------

    /**
     * Draw the blocks for the public site. One that fails is left out (and,
     * for an insert's, noted on the shelf); the rest still show.
     */
    public static function render(mixed $blocks, array $context = []): HtmlString
    {
        $kinds = self::kinds();
        $html = '';

        foreach (is_array($blocks) ? $blocks : [] as $i => $block) {
            if (! is_array($block) || ! ($block['enabled'] ?? true) || ! isset($kinds[$block['type'] ?? ''])) {
                continue;
            }
            $kind = $kinds[$block['type']];
            $values = self::forEditor([$block])[0] ?? null;
            if ($values === null) {
                continue;
            }

            try {
                $out = ViewState::protect(function () use ($kind, $values, $context, $i) {
                    if (isset($kind['insert'])) {
                        $drawn = ($kind['show'])($values, $kind['insert'], $context);
                        $drawn = $drawn instanceof Htmlable ? $drawn->toHtml() : (string) $drawn;

                        return trim($drawn) === '' ? '' : '<div class="block block--insert block--'.e(str_replace('.', '-', $values['type'])).'">'.$drawn.'</div>';
                    }

                    return view('site.blocks.'.$values['type'], ['block' => $values, 'site' => app(Site::class), 'index' => $i, 'context' => $context])->render();
                });
                $html .= $out;
            } catch (Throwable $e) {
                report($e);
                if (isset($kind['insert'])) {
                    app(InsertManager::class)->noteError($kind['insert']->key, $e);
                }
            }
        }

        return new HtmlString($html === '' ? '' : '<div class="blocks">'.$html.'</div>');
    }

    /**
     * Every field of an entry's blueprint, fieldsets opened up, in order:
     * [handle => config].
     *
     * @return array<string, array>
     */
    public static function blueprintFields(Entry $entry): array
    {
        $fields = [];
        foreach ((array) $entry->blueprint()?->fields() as $field) {
            $list = isset($field['import'])
                ? collect((array) Booklet::fieldsets()->find($field['import'])?->get('fields', []))
                    ->map(fn ($f) => isset($f['handle']) ? ['handle' => ($field['prefix'] ?? '').$f['handle']] + $f : $f)->all()
                : [$field];
            foreach ($list as $f) {
                if (isset($f['handle']) && is_string($f['handle'])) {
                    $fields[$f['handle']] = (array) ($f['field'] ?? []);
                }
            }
        }

        return $fields;
    }

    /** The handles of an entry's fields of one type (blocks by default), in blueprint order. */
    public static function fieldsOf(Entry $entry, string $type = 'blocks'): array
    {
        return array_keys(array_filter(self::blueprintFields($entry), fn ($f) => ($f['type'] ?? null) === $type));
    }

    /** Every blocks field of an entry, drawn one after another. */
    public static function forEntry(Entry $entry, array $context = []): HtmlString
    {
        $html = '';
        $fields = self::blueprintFields($entry);
        foreach (self::fieldsOf($entry) as $handle) {
            if (! Conditions::passes($fields[$handle] ?? [], $entry->data())) {
                continue; // switched off by a show-this-field-when rule
            }
            $html .= self::render($entry->get($handle), $context + ['entry' => $entry, 'field' => $handle])->toHtml();
        }

        return new HtmlString($html);
    }

    /** The words in an entry's blocks and rows, plain (for search, reading time and Stacy). */
    public static function textOf(Entry $entry): string
    {
        $parts = [];
        foreach (self::blueprintFields($entry) as $handle => $field) {
            $value = $entry->get($handle);
            if (! is_array($value) || $value === []) {
                continue;
            }
            $parts[] = match ($field['type'] ?? null) {
                'blocks' => self::text($value),
                'grid' => Rows::text($value, Rows::columns($field)),
                default => '',
            };
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /**
     * An entry's linked entries and rows, for readers: each field with its
     * name as a heading, in blueprint order (a field with 'show_on_site: false'
     * is left for the theme to place itself).
     */
    public static function extrasFor(Entry $entry): HtmlString
    {
        $site = app(Site::class);
        $html = '';
        foreach (self::blueprintFields($entry) as $handle => $field) {
            $type = $field['type'] ?? null;
            if (! in_array($type, ['entries', 'grid'], true) || ($field['show_on_site'] ?? true) === false || ! Conditions::passes($field, $entry->data())) {
                continue;
            }
            try {
                $html .= ViewState::protect(fn () => $type === 'entries'
                    ? (($linked = Links::entries($entry->get($handle))->reject(fn ($e) => $e->id() === $entry->id()))->isEmpty() ? ''
                        : view('site.fields.entries', ['title' => (string) ($field['display'] ?? Str::headline($handle)), 'entries' => $linked, 'site' => $site, 'handle' => $handle])->render())
                    : (($rows = Rows::forEditor($entry->get($handle), $columns = Rows::columns($field))) === [] ? ''
                        : view('site.fields.grid', ['title' => (string) ($field['display'] ?? Str::headline($handle)), 'rows' => $rows, 'columns' => $columns, 'site' => $site, 'handle' => $handle])->render()));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return new HtmlString($html);
    }

    /** The words in some blocks, as plain text. */
    public static function text(mixed $blocks): string
    {
        $kinds = self::kinds();
        $parts = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block) || ! ($block['enabled'] ?? true)) {
                continue;
            }
            foreach ($kinds[$block['type'] ?? '']['fields'] ?? [] as $handle => $field) {
                if (in_array($field['type'], ['text', 'textarea', 'markdown'], true) && is_string($block[$handle] ?? null)) {
                    $parts[] = trim(strip_tags((string) \App\Support\Markdown::html(InsertManager::stripSlips($block[$handle]), ['html_input' => 'strip'])));
                }
            }
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /** In a line, for History: "Quote “Kopi first…” · Picture (kopi.jpg)". */
    public static function summary(mixed $blocks): string
    {
        $kinds = self::kinds();

        return collect(is_array($blocks) ? $blocks : [])->filter(fn ($b) => is_array($b))->map(function (array $block) use ($kinds) {
            $kind = $kinds[$block['type'] ?? ''] ?? null;
            $name = $kind['name'] ?? Str::headline(Str::afterLast((string) ($block['type'] ?? 'block'), '.'));
            $what = '';
            foreach ($kind['fields'] ?? [] as $handle => $field) {
                $v = $block[$handle] ?? null;
                if (in_array($field['type'], ['text', 'textarea', 'markdown'], true) && is_string($v) && trim($v) !== '') {
                    $what = '“'.Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($v))), 40).'”';
                    break;
                }
                if ($field['type'] === 'entries' && is_array($v) && $v !== []) {
                    $what = '('.Links::entries($v, false)->map->title()->implode(', ').')';
                    break;
                }
                if (in_array($field['type'], ['picture', 'pictures'], true) && is_array($v) && $v !== []) {
                    $what = '('.collect($v)->map(fn ($p) => basename((string) $p))->implode(', ').')';
                    break;
                }
            }

            return trim($name.' '.$what).(($block['enabled'] ?? true) ? '' : ' [hidden]');
        })->implode(' · ');
    }

    // Small helpers for the views ---------------------------------------------------------------

    /**
     * A picture's address, smaller copies, focal point and description, in a
     * named size when one is given (and the picture can be cut): then 'ratio'
     * is its shape for CSS ("1200 / 900").
     */
    public static function picture(?string $path, string $container = 'media', ?string $size = null): ?array
    {
        if (! $path) {
            return null;
        }
        $disk = Booklet::assetContainers()->find($container)?->attribute('disk') ?? 'public';

        if ($size && ($preset = Crops::sizes()[$size] ?? null)) {
            return [
                'url' => (string) Crops::url($path, $size, $container),
                'srcset' => Crops::srcset($path, $size, $container),
                'focus' => Focus::forPath($path, $container), // still right if the crop couldn't be cut
                'alt' => (string) Booklet::assets()->find(['container' => $container, 'path' => $path])?->get('alt', ''),
                'ratio' => $preset['width'].' / '.$preset['height'],
            ];
        }

        return [
            'url' => Disk::of($disk)->url($path),
            'srcset' => Pictures::srcset($path, $container),
            'focus' => Focus::forPath($path, $container),
            'alt' => (string) Booklet::assets()->find(['container' => $container, 'path' => $path])?->get('alt', ''),
        ];
    }

    /** A privacy-friendly player address for a YouTube or Vimeo link, or null. */
    public static function videoEmbed(string $url): ?string
    {
        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,20})#', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }
        if (preg_match('#^https?://(?:www\.)?(?:player\.)?vimeo\.com/(?:video/)?(\d{4,12})#', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1].'?dnt=1';
        }

        return null;
    }
}
