<?php

namespace App\Booklet\Stores;

use App\Booklet\Schema\Definition;

/**
 * Where one kind of item is kept. Records are plain arrays:
 *   ['attributes' => [...], 'data' => [...], 'updated_at' => ?string]
 *
 * Booklet ships a database store and a file store; point a group in
 * config/booklet.php at your own class to store things elsewhere.
 */
interface Store
{
    public function __construct(Definition $definition);

    /**
     * @return array<int, array{attributes: array, data: array, updated_at: ?string}>
     */
    public function all(): array;

    /**
     * @param  array<string, string>  $key  key column => value
     */
    public function find(array $key): ?array;

    /**
     * @param  array<string, mixed>  $filters  attribute (or data key) => value
     */
    public function where(array $filters): array;

    public function save(array $attributes, array $data): void;

    /**
     * @param  array<string, string>  $key
     */
    public function delete(array $key): void;

    /**
     * Remove every item of this kind.
     */
    public function truncate(): void;
}
