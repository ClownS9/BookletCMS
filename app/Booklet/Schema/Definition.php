<?php

namespace App\Booklet\Schema;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Describes one kind of stored thing (entries, terms, trees…) in a way both
 * storage drivers understand: which table and columns it uses in the database,
 * and where and how it is written as a file.
 *
 * Every item has fixed "attributes" (the columns) and a free-form "data" bag
 * (the JSON column). In files, attributes and data sit side by side in the
 * YAML, unless `fileNestKey` is set, in which case data goes under that key.
 */
class Definition
{
    /**
     * @param  string  $name  repository name, e.g. "entries"
     * @param  string  $group  config key in booklet.drivers, e.g. "entries"
     * @param  class-string<\App\Booklet\Data\Item>  $item
     * @param  class-string<\App\Booklet\Repositories\Repository>  $repository
     * @param  string[]  $columns  fixed attributes, stored as table columns
     * @param  string[]  $key  attributes that identify an item
     * @param  string  $json  name of the JSON column that holds the data bag
     * @param  array<string, string>  $casts  attribute => bool|int|datetime|array
     * @param  bool  $uuid  generate a UUID for the first key column when missing
     * @param  string  $fileRoot  booklet.paths key: content|resources|storage
     * @param  string  $fileDir  directory under that root
     * @param  string  $fileExtension  yaml|md
     * @param  Closure(array): string  $filePath  attributes → path relative to fileDir
     * @param  Closure(string): ?array  $parsePath  relative path → attributes it encodes, or null to skip
     */
    public function __construct(
        public readonly string $name,
        public readonly string $group,
        public readonly string $item,
        public readonly string $repository,
        public readonly string $table,
        public readonly array $columns,
        public readonly array $key,
        public readonly string $json,
        public readonly array $casts,
        public readonly bool $uuid,
        public readonly string $fileRoot,
        public readonly string $fileDir,
        public readonly string $fileExtension,
        public readonly Closure $filePath,
        public readonly Closure $parsePath,
        public readonly ?string $fileNestKey = null,
    ) {}

    public function keyString(array $attributes): string
    {
        return implode('::', array_map(
            fn ($column) => (string) ($attributes[$column] ?? ''),
            $this->key,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function keyValues(string|array $key): array
    {
        if (is_array($key)) {
            return array_intersect_key($key, array_flip($this->key));
        }

        $parts = count($this->key) === 1 ? [$key] : explode('::', $key, count($this->key));

        if (count($parts) !== count($this->key)) {
            throw new \InvalidArgumentException(
                "Booklet [{$this->name}] keys look like ".implode('::', $this->key).", got [{$key}]."
            );
        }

        return array_combine($this->key, $parts);
    }

    public function hasCompleteKey(array $attributes): bool
    {
        foreach ($this->key as $column) {
            if (($attributes[$column] ?? null) === null || $attributes[$column] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Bring attributes from either driver to one shape: bools are bools,
     * dates are "Y-m-d H:i:s" strings, JSON columns are arrays.
     */
    public function castAttributes(array $attributes): array
    {
        $out = [];

        foreach ($this->columns as $column) {
            $value = $attributes[$column] ?? null;
            $out[$column] = $value === null ? null : match ($this->casts[$column] ?? null) {
                'bool' => (bool) $value,
                'int' => (int) $value,
                'datetime' => static::normalizeDate($value),
                'array' => is_string($value) ? (json_decode($value, true) ?? []) : (array) $value,
                default => is_scalar($value) ? (string) $value : $value,
            };
        }

        return $out;
    }

    public function path(array $attributes): string
    {
        return ($this->filePath)($attributes);
    }

    public function parse(string $relativePath): ?array
    {
        return ($this->parsePath)($relativePath);
    }

    /**
     * Attributes that the file path already encodes, so they are not repeated
     * inside the file (e.g. an entry's collection and slug).
     */
    public function derivedFromPath(array $attributes): array
    {
        return array_keys($this->parse($this->path($attributes)) ?? []);
    }

    public static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d H:i:s');
        }

        if (is_int($value)) { // YAML turns unquoted dates into timestamps
            return Carbon::createFromTimestamp($value)->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
