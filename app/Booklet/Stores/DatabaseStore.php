<?php

namespace App\Booklet\Stores;

use App\Booklet\Schema\Definition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Keeps items in the tables from the Booklet migrations: fixed attributes in
 * their own columns, the data bag in one JSON column (as Statamic's
 * eloquent driver does).
 */
class DatabaseStore implements Store
{
    public function __construct(protected Definition $definition) {}

    protected function query(): Builder
    {
        return DB::connection(config('booklet.database.connection'))->table($this->definition->table);
    }

    public function all(): array
    {
        return $this->query()->get()->map(fn ($row) => $this->toRecord((array) $row))->all();
    }

    /**
     * The most recently changed, newest first: asked of the table, so a
     * big book isn't read whole for "Recently edited".
     *
     * @return array<int, array{attributes: array, data: array, updated_at: ?string}>
     */
    public function latest(int $take): array
    {
        return $this->query()->orderByDesc('updated_at')->limit($take)->get()->map(fn ($row) => $this->toRecord((array) $row))->all();
    }

    public function find(array $key): ?array
    {
        $row = $this->query()->where($key)->first();

        return $row ? $this->toRecord((array) $row) : null;
    }

    public function where(array $filters): array
    {
        $columns = array_intersect_key($filters, array_flip($this->definition->columns));
        $dataFilters = array_diff_key($filters, $columns);

        $query = $this->query();

        foreach ($columns as $column => $value) {
            is_array($value) ? $query->whereIn($column, $value) : $query->where($column, $value);
        }

        $records = $query->get()->map(fn ($row) => $this->toRecord((array) $row))->all();

        if ($dataFilters === []) {
            return $records;
        }

        // Data keys are filtered here rather than with JSON queries, so the
        // same filters behave identically on SQLite, MySQL and Postgres.
        return array_values(array_filter($records, function (array $record) use ($dataFilters) {
            foreach ($dataFilters as $key => $value) {
                $actual = Arr::get($record['data'], $key);
                if (is_array($value) ? ! in_array($actual, $value, true) : $actual !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function save(array $attributes, array $data): void
    {
        $key = $this->definition->keyValues($attributes);
        $now = now()->format('Y-m-d H:i:s');

        $values = [];
        foreach ($this->definition->columns as $column) {
            $value = $attributes[$column] ?? null;
            $values[$column] = ($this->definition->casts[$column] ?? null) === 'array' && $value !== null
                ? $this->encode($value)
                : $value;
        }
        $values[$this->definition->json] = $this->encode($data);
        $values['updated_at'] = $now;

        DB::connection(config('booklet.database.connection'))->transaction(function () use ($key, $values, $now) {
            if ($this->query()->where($key)->exists()) {
                $this->query()->where($key)->update($values);
            } else {
                $this->query()->insert($values + ['created_at' => $now]);
            }
        });
    }

    public function delete(array $key): void
    {
        $this->query()->where($key)->delete();
    }

    public function truncate(): void
    {
        $this->query()->delete();
    }

    protected function toRecord(array $row): array
    {
        $data = $row[$this->definition->json] ?? null;

        return [
            'attributes' => Arr::only($row, $this->definition->columns),
            'data' => is_string($data) ? (json_decode($data, true) ?? []) : (array) $data,
            'updated_at' => $row['updated_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    protected function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
