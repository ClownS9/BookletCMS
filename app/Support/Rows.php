<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Rows: a small table inside an entry (Statamic's Grid), like ingredients,
 * team members or opening hours. The columns are set in the blueprint the
 * way Statamic sets them:
 *
 *   ingredients:
 *     type: grid
 *     fields:
 *       - handle: amount
 *         field: { type: text, display: Amount }
 *       - handle: item
 *         field: { type: text, display: Item }
 *
 * and each row is kept with its own id:  - { id: k3x9q2, amount: 200g, item: Flour }
 */
class Rows
{
    public const COLUMN_TYPES = ['text' => 'Words', 'textarea' => 'Long words', 'number' => 'Number', 'link' => 'Link', 'toggle' => 'Yes or no', 'picture' => 'Picture'];

    public const MAX_ROWS = 100;

    /**
     * A grid field's columns.
     *
     * @return array<string, array{type: string, display: string}>
     */
    public static function columns(array $field): array
    {
        $columns = [];
        foreach ((array) ($field['fields'] ?? []) as $column) {
            $handle = (string) ($column['handle'] ?? '');
            $config = (array) ($column['field'] ?? []);
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $handle) || $handle === 'id') {
                continue;
            }
            $type = (string) ($config['type'] ?? 'text');
            $type = $type === 'assets' ? 'picture' : $type;
            $columns[$handle] = [
                'type' => array_key_exists($type, self::COLUMN_TYPES) ? $type : 'text',
                'display' => (string) ($config['display'] ?? Str::headline($handle)),
            ];
        }

        return $columns;
    }

    /** A new, empty row for these columns. */
    public static function blank(array $columns): array
    {
        $row = ['id' => Blocks::id()];
        foreach ($columns as $handle => $column) {
            $row[$handle] = match ($column['type']) {
                'toggle' => false,
                'picture' => [],
                default => '',
            };
        }

        return $row;
    }

    /** Rows from storage, ready for the editor: every column there, ids given. */
    public static function forEditor(mixed $rows, array $columns): array
    {
        $out = [];
        foreach (array_slice(is_array($rows) ? array_values($rows) : [], 0, self::MAX_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ready = ['id' => is_string($row['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $row['id']) ? $row['id'] : Blocks::id()];
            foreach ($columns as $handle => $column) {
                $ready[$handle] = self::value($row[$handle] ?? null, $column);
            }
            $out[] = $ready;
        }

        return $out;
    }

    /** What the editor sent, made safe to keep. Rows with nothing in them are dropped. */
    public static function clean(mixed $rows, array $columns): array
    {
        $out = [];
        foreach (self::forEditor($rows, $columns) as $row) {
            $kept = ['id' => $row['id']];
            foreach ($columns as $handle => $column) {
                $value = $row[$handle];
                if ($value !== '' && $value !== [] && $value !== false) {
                    $kept[$handle] = $value;
                }
            }
            if (count($kept) > 1) {
                $out[] = $kept;
            }
        }

        return $out;
    }

    protected static function value(mixed $value, array $column): mixed
    {
        $scalar = is_scalar($value) ? (string) $value : '';

        return match ($column['type']) {
            'toggle' => (bool) $value,
            'number' => is_numeric($value) ? $value + 0 : '',
            'link' => Blocks::safeLink($scalar) ?? '',
            'picture' => array_slice(array_values(array_filter((array) $value, fn ($p) => is_string($p) && $p !== '' && ! str_contains($p, '..') && ! str_starts_with($p, '/'))), 0, 1),
            'textarea' => mb_substr(str_replace("\r\n", "\n", $scalar), 0, 2000),
            default => Str::limit(trim(str_replace(["\r", "\n"], ' ', $scalar)), 300, ''),
        };
    }

    /** The words in some rows, plain (for search). */
    public static function text(mixed $rows, array $columns): string
    {
        return collect(self::forEditor($rows, $columns))->map(fn ($row) => collect($columns)
            ->filter(fn ($c) => in_array($c['type'], ['text', 'textarea'], true))
            ->map(fn ($c, $h) => $row[$h])->filter()->implode(' · '))
            ->filter()->implode("\n");
    }

    /** In a line, for History: "3 rows: 200g Flour; 2 Eggs; …". */
    public static function summary(mixed $rows, array $columns): string
    {
        $rows = self::forEditor($rows, $columns);
        if ($rows === []) {
            return '';
        }
        $lines = collect($rows)->take(4)->map(fn ($row) => collect($columns)
            ->map(fn ($c, $h) => match ($c['type']) {
                'picture' => isset($row[$h][0]) ? basename($row[$h][0]) : '',
                'toggle' => $row[$h] ? $c['display'] : '',
                default => (string) $row[$h],
            })->filter()->implode(' '))->implode('; ');

        return count($rows).' '.Str::plural('row', count($rows)).': '.$lines.(count($rows) > 4 ? '; …' : '');
    }
}
