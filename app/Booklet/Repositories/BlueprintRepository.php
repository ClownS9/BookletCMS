<?php

namespace App\Booklet\Repositories;

use App\Booklet\Data\Blueprint;
use App\Booklet\Data\Entry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Repository<Blueprint>
 */
class BlueprintRepository extends Repository
{
    /**
     * @return Collection<int, Blueprint>
     */
    public function inNamespace(string $namespace): Collection
    {
        return $this->where(['namespace' => $namespace])
            ->sortBy(fn (Blueprint $b) => [$b->get('order', 0), $b->title()])
            ->values();
    }

    public function findIn(string $namespace, string $handle): ?Blueprint
    {
        return $this->find(['namespace' => $namespace, 'handle' => $handle]);
    }

    /**
     * The entry's own blueprint, or the collection's first one (Statamic's rule).
     */
    public function forEntry(Entry $entry): ?Blueprint
    {
        $namespace = 'collections.'.$entry->collectionHandle();

        if ($handle = $entry->attribute('blueprint')) {
            return $this->findIn($namespace, $handle);
        }

        return $this->findIn($namespace, Str::singular($entry->collectionHandle()))
            ?? $this->inNamespace($namespace)->first();
    }
}
