<?php

namespace App\Inserts;

use App\Models\InsertRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * An insert's own storage, one kind at a time: $insert->records('albums').
 * No migration needed: each record is a slug, a sort order and its fields.
 */
class Records
{
    public function __construct(protected string $insert, protected string $kind) {}

    protected function query()
    {
        return InsertRecord::query()->where('insert', $this->insert)->where('kind', $this->kind);
    }

    /** @return Collection<int, InsertRecord> in their order */
    public function all(): Collection
    {
        return $this->query()->orderBy('sort')->orderBy('id')->get();
    }

    public function find(int|string|null $id): ?InsertRecord
    {
        return $id ? $this->query()->whereKey($id)->first() : null;
    }

    public function findBySlug(?string $slug): ?InsertRecord
    {
        return $slug ? $this->query()->where('slug', $slug)->first() : null;
    }

    /** Add one. A slug is made from `title` (or `name`) unless one is given, and kept unique. */
    public function create(array $data, ?string $slug = null): InsertRecord
    {
        return InsertRecord::create([
            'insert' => $this->insert,
            'kind' => $this->kind,
            'slug' => $this->uniqueSlug($slug ?? (string) ($data['title'] ?? $data['name'] ?? '')),
            'data' => $data,
            'sort' => (int) $this->query()->max('sort') + 1,
        ]);
    }

    public function update(InsertRecord $record, array $data): InsertRecord
    {
        $record->forceFill(['data' => array_merge((array) $record->data, $data)])->save();

        return $record;
    }

    public function delete(InsertRecord|int|string $record): void
    {
        $record = $record instanceof InsertRecord ? $record : $this->find($record);
        $record?->delete();
    }

    /** Put records in this order (ids, first to last). */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            $this->query()->whereKey($id)->update(['sort' => $i + 1]);
        }
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    protected function uniqueSlug(string $from): ?string
    {
        $base = Str::slug($from) ?: null;
        if ($base === null) {
            return null;
        }
        $slug = $base;
        for ($i = 2; $this->query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
