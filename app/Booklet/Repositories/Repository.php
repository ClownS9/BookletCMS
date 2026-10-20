<?php

namespace App\Booklet\Repositories;

use App\Booklet\Data\Item;
use App\Booklet\Schema\Definition;
use App\Booklet\Stores\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads and writes one kind of item. It never knows which driver is behind
 * it: the same calls work against the database and against files.
 *
 * @template TItem of Item
 */
class Repository
{
    /** Every record, keyed, once all() has been asked for in this request. */
    protected ?array $records = null;

    /** Single finds, including misses (false), so repeat lookups are free. */
    protected array $found = [];

    public function __construct(
        protected Definition $definition,
        protected Store $store,
    ) {}

    /**
     * Forget what this request has read, e.g. after files changed on disk.
     */
    public function forget(): void
    {
        $this->records = null;
        $this->found = [];
    }

    /**
     * @return array<string, array>
     */
    protected function records(): array
    {
        if ($this->records === null) {
            $this->records = [];
            foreach ($this->store->all() as $record) {
                $this->records[$this->definition->keyString($record['attributes'])] = $record;
            }
        }

        return $this->records;
    }

    public function definition(): Definition
    {
        return $this->definition;
    }

    public function store(): Store
    {
        return $this->store;
    }

    /**
     * @return TItem
     */
    public function make(array $attributes = [], array $data = []): Item
    {
        return $this->definition->item::make($attributes, $data);
    }

    /**
     * @return Collection<int, TItem>
     */
    public function all(): Collection
    {
        return $this->hydrateMany(array_values($this->records()));
    }

    /**
     * @param  string|array  $key  "handle", "taxonomy::slug::site", or [column => value]
     * @return TItem|null
     */
    public function find(string|array $key): ?Item
    {
        $values = $this->definition->keyValues($key);
        $string = $this->definition->keyString($values);

        if ($this->records !== null) {
            $record = $this->records[$string] ?? null;
        } else {
            $record = $this->found[$string] ??= ($this->store->find($values) ?? false);
        }

        return $record ? $this->hydrate($record) : null;
    }

    /**
     * @return TItem
     */
    public function findOrFail(string|array $key): Item
    {
        return $this->find($key) ?? throw new RuntimeException(
            "Booklet [{$this->definition->name}] item [".(is_array($key) ? implode('::', $key) : $key).'] not found.'
        );
    }

    /**
     * @param  array<string, mixed>  $filters  attribute or data key => value (or list of values)
     * @return Collection<int, TItem>
     */
    public function where(array $filters): Collection
    {
        return $this->hydrateMany($this->store->where($filters));
    }

    public function count(): int
    {
        return count($this->records());
    }

    /**
     * @param  TItem  $item
     * @return TItem
     */
    public function save(Item $item): Item
    {
        $this->prepare($item);

        if ($this->definition->uuid && ! $item->attribute($this->definition->key[0])) {
            $item->setAttribute($this->definition->key[0], $this->newId());
        }

        if (! $this->definition->hasCompleteKey($item->attributes())) {
            throw new RuntimeException(
                "Booklet [{$this->definition->name}] needs ".implode(', ', $this->definition->key).' before saving.'
            );
        }

        $attributes = $this->definition->castAttributes($item->attributes());
        $item->setAttributes(array_filter($attributes, fn ($v) => $v !== null) + $item->attributes());

        $this->store->save($attributes, $item->data());
        $item->setLastModified(now()->format('Y-m-d H:i:s'));

        $string = $this->definition->keyString($attributes);
        $record = ['attributes' => $attributes, 'data' => $item->data(), 'updated_at' => $item->lastModified()];
        $this->found[$string] = $record;
        if ($this->records !== null) {
            $this->records[$string] = $record;
        }

        app('events')->dispatch('booklet.changed', [$this->definition->name]);

        return $item;
    }

    public function delete(Item|string|array $item): void
    {
        $key = $this->definition->keyValues($item instanceof Item ? $item->attributes() : $item);

        $this->store->delete($key);

        $string = $this->definition->keyString($key);
        $this->found[$string] = false;
        unset($this->records[$string]);

        app('events')->dispatch('booklet.changed', [$this->definition->name]);
    }

    /**
     * Fill in derived attributes before saving (URIs, defaults…).
     */
    protected function prepare(Item $item): void {}

    /**
     * Time-ordered, so sorting ids also sorts by creation in both drivers.
     */
    protected function newId(): string
    {
        return (string) Str::orderedUuid();
    }

    protected function hydrate(array $record): Item
    {
        return $this->make($this->definition->castAttributes($record['attributes']), $record['data'])
            ->setLastModified($record['updated_at'] ?? null);
    }

    protected function hydrateMany(array $records): Collection
    {
        return collect($records)->map(fn (array $record) => $this->hydrate($record))->values();
    }
}
