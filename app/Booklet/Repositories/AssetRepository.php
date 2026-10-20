<?php

namespace App\Booklet\Repositories;

use App\Booklet\Data\Asset;
use App\Booklet\Data\Item;
use Illuminate\Support\Collection;

/**
 * @extends Repository<Asset>
 */
class AssetRepository extends Repository
{
    /**
     * @return Collection<int, Asset>
     */
    public function inContainer(string $container, ?string $folder = null): Collection
    {
        $filters = ['container' => $container];
        if ($folder !== null) {
            $filters['folder'] = $folder;
        }

        return $this->where($filters)->sortBy(fn (Asset $a) => $a->path())->values();
    }

    /**
     * @param  Asset  $item
     */
    protected function prepare(Item $item): void
    {
        $item->setAttributes(array_merge(
            $item->attributes(),
            Asset::partsFromPath($item->container(), $item->path()),
        ));
    }
}
