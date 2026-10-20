<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What changed between two editions of an entry, beyond its words: the
 * standfirst, tags, cover, search text, address, date… in plain words,
 * and which of them can be put back one at a time.
 */
class EditionCompare
{
    /** The words are compared on their own (word by word), so they're not in the list. */
    public const WORDS = ['title', 'content'];

    /**
     * The entry's fields (blueprint order, fieldsets opened up), plus the
     * address, date and author, which aren't in the blueprint.
     *
     * @return array<string, array{label: string, type: string, options: array, taxonomy: ?string, attribute: bool, config?: array}>
     */
    public static function fields(Entry $entry): array
    {
        $fields = [];
        foreach ((array) $entry->blueprint()?->fields() as $field) {
            $list = isset($field['import'])
                ? collect((array) Booklet::fieldsets()->find($field['import'])?->get('fields', []))
                    ->map(fn ($f) => isset($f['handle']) ? ['handle' => ($field['prefix'] ?? '').$f['handle']] + $f : $f)->all()
                : [$field];
            foreach ($list as $f) {
                $handle = (string) ($f['handle'] ?? '');
                if ($handle === '' || in_array($handle, self::WORDS, true)) {
                    continue;
                }
                $config = (array) ($f['field'] ?? []);
                $fields[$handle] = [
                    'label' => (string) ($config['display'] ?? Str::headline($handle)),
                    'type' => (string) ($config['type'] ?? 'text'),
                    'options' => (array) ($config['options'] ?? []),
                    'taxonomy' => ((array) ($config['taxonomies'] ?? []))[0] ?? null,
                    'attribute' => false,
                    'config' => $config,
                ];
            }
        }

        return $fields + [
            'author' => ['label' => 'Byline', 'type' => 'author', 'options' => [], 'taxonomy' => null, 'attribute' => false],
            'slug' => ['label' => 'Address', 'type' => 'slug', 'options' => [], 'taxonomy' => null, 'attribute' => true],
            'date' => ['label' => 'Date', 'type' => 'date', 'options' => [], 'taxonomy' => null, 'attribute' => true],
        ];
    }

    /** One field's value in an edition's snapshot (or the entry as it is now). */
    public static function value(array $snapshot, string $handle, array $field): mixed
    {
        return $field['attribute'] ? ($snapshot['attributes'][$handle] ?? null) : ($snapshot['data'][$handle] ?? null);
    }

    /** The entry now, shaped like an edition's snapshot. */
    public static function now(Entry $entry): array
    {
        return ['attributes' => $entry->attributes(), 'data' => $entry->data()];
    }

    /**
     * Fields that differ between two snapshots, older first.
     *
     * @return array<int, array{handle: string, label: string, before: string, after: string, long: bool}>
     */
    public static function changes(Entry $entry, array $older, array $newer): array
    {
        $rows = [];
        foreach (self::fields($entry) as $handle => $field) {
            $a = self::value($older, $handle, $field);
            $b = self::value($newer, $handle, $field);
            if (self::same($a, $b, $field)) {
                continue;
            }
            $rows[] = [
                'handle' => $handle,
                'label' => $field['label'],
                'before' => self::words($a, $field),
                'after' => self::words($b, $field),
                'long' => in_array($field['type'], ['textarea', 'markdown'], true),
            ];
        }

        return $rows;
    }

    public static function same(mixed $a, mixed $b, array $field): bool
    {
        $blank = fn ($v) => $v === null || $v === '' || $v === [] || $v === false;
        if ($blank($a) && $blank($b)) {
            return true;
        }
        if ($field['type'] === 'date') {
            return self::time($a)?->format('Y-m-d H:i') === self::time($b)?->format('Y-m-d H:i');
        }
        if (is_array($a) || is_array($b)) {
            return array_values((array) $a) === array_values((array) $b);
        }

        return trim((string) $a) === trim((string) $b);
    }

    protected static function time(mixed $v): ?Carbon
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance($v);
        }

        return $v ? rescue(fn () => Carbon::parse((string) $v), null, false) : null;
    }

    /** A value in plain words, the way a person would say it. */
    public static function words(mixed $value, array $field): string
    {
        $blank = $value === null || $value === '' || $value === [] || ($value === false && $field['type'] !== 'toggle');
        if ($blank) {
            return '';
        }

        return match ($field['type']) {
            'toggle' => $value ? 'Yes' : 'No',
            'select', 'radio' => (string) ($field['options'][(string) $value] ?? $value),
            'terms' => collect((array) $value)->map(fn ($slug) => $field['taxonomy']
                ? (Booklet::terms()->findBySlug($field['taxonomy'], (string) $slug)?->title() ?? (string) $slug) : (string) $slug)->implode(', '),
            'blocks' => Blocks::summary($value),
            'grid' => Rows::summary($value, Rows::columns((array) ($field['config'] ?? []))),
            'entries' => collect((array) $value)->map(fn ($id) => is_string($id) ? (Booklet::entries()->find($id)?->title() ?: 'a page that’s gone') : null)->filter()->implode(', '),
            'assets' => collect((array) $value)->map(fn ($path) => basename((string) $path))->implode(', '),
            'author' => (string) (User::find($value)?->name ?? 'Someone who has left'),
            'date' => (string) self::time($value)?->format('j M Y, g:ia'),
            'slug' => (string) $value,
            default => is_array($value) ? collect($value)->flatten()->filter(fn ($v) => is_scalar($v))->implode(', ') : trim((string) $value),
        };
    }
}
