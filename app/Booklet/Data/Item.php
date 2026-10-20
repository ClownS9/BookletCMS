<?php

namespace App\Booklet\Data;

use App\Booklet\Facades\Booklet;
use App\Booklet\Schema\Definition;
use App\Booklet\Schema\Definitions;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

/**
 * Base for everything Booklet stores. An item has fixed attributes (the
 * table columns, e.g. an entry's slug and site) and a free-form data bag
 * (the JSON column, e.g. an entry's title and body as its blueprint defines).
 */
abstract class Item implements Arrayable
{
    protected array $attributes = [];

    protected array $data = [];

    protected ?string $lastModified = null;

    final public function __construct(array $attributes = [], array $data = [])
    {
        $this->attributes = $attributes;
        $this->data = $data;
    }

    public static function make(array $attributes = [], array $data = []): static
    {
        return new static($attributes, $data);
    }

    /**
     * The repository this item belongs to, e.g. "entries".
     */
    abstract public static function repositoryName(): string;

    public static function definition(): Definition
    {
        return Definitions::get(static::repositoryName());
    }

    public function key(): string
    {
        return static::definition()->keyString($this->attributes);
    }

    // Attributes (columns) ------------------------------------------------

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    // Data (JSON column) --------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function set(string $key, mixed $value): static
    {
        Arr::set($this->data, $key, $value);

        return $this;
    }

    public function has(string $key): bool
    {
        return Arr::has($this->data, $key);
    }

    public function remove(string $key): static
    {
        Arr::forget($this->data, $key);

        return $this;
    }

    public function merge(array $data): static
    {
        $this->data = array_replace_recursive($this->data, $data);

        return $this;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    // Persistence ---------------------------------------------------------

    public function lastModified(): ?string
    {
        return $this->lastModified;
    }

    public function setLastModified(?string $timestamp): static
    {
        $this->lastModified = $timestamp;

        return $this;
    }

    public function save(): static
    {
        Booklet::repository(static::repositoryName())->save($this);

        return $this;
    }

    public function delete(): void
    {
        Booklet::repository(static::repositoryName())->delete($this);
    }

    public function fresh(): ?static
    {
        $found = Booklet::repository(static::repositoryName())->find($this->key());

        return $found instanceof static ? $found : null;
    }

    /**
     * Attributes win over data keys with the same name.
     */
    public function toArray(): array
    {
        return array_merge($this->data, $this->attributes);
    }

    public function __get(string $key): mixed
    {
        return array_key_exists($key, $this->attributes)
            ? $this->attributes[$key]
            : $this->get($key);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || $this->has($key);
    }
}
