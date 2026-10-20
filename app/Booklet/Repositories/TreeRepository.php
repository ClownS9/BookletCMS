<?php

namespace App\Booklet\Repositories;

use App\Booklet\Data\Tree;

/**
 * @extends Repository<Tree>
 */
class TreeRepository extends Repository
{
    public function navigation(string $handle, ?string $site = null): ?Tree
    {
        return $this->find(['type' => 'navigation', 'handle' => $handle, 'locale' => $site ?? config('booklet.default_site')]);
    }

    public function collection(string $handle, ?string $site = null): ?Tree
    {
        return $this->find(['type' => 'collection', 'handle' => $handle, 'locale' => $site ?? config('booklet.default_site')]);
    }

    /**
     * The tree for a navigation or collection, or a new empty one ready to save.
     */
    public function findOrMake(string $type, string $handle, ?string $site = null): Tree
    {
        $attributes = ['type' => $type, 'handle' => $handle, 'locale' => $site ?? config('booklet.default_site')];

        return $this->find($attributes) ?? $this->make($attributes, []);
    }
}
