<?php

namespace App\Booklet\Repositories;

use App\Booklet\Data\Item;
use App\Booklet\Data\Revision;
use Illuminate\Support\Collection;

/**
 * @extends Repository<Revision>
 */
class RevisionRepository extends Repository
{
    /**
     * Every edition of one item, newest first.
     *
     * @return Collection<int, Revision>
     */
    public function whereKey(string $key): Collection
    {
        return $this->where(['key' => $key])->sort(
            fn (Revision $a, Revision $b) => [$b->get('date'), $b->id()] <=> [$a->get('date'), $a->id()]
        )->values();
    }

    /**
     * Save a snapshot of an item as a new edition.
     */
    public function snapshot(string $key, Item $item, string $action = 'revision', ?string $user = null, ?string $message = null): Revision
    {
        /** @var Revision $revision */
        $revision = $this->make(
            ['key' => $key, 'action' => $action, 'user' => $user, 'message' => $message],
            ['date' => now()->format('Y-m-d H:i:s'), 'attributes' => $item->attributes(), 'data' => $item->data()],
        );

        return $this->save($revision);
    }
}
