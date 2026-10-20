<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something torn out of the book, waiting in the bin (see App\Support\Bin).
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $kind
 * @property string $item_key
 * @property string $title
 * @property string|null $meta
 * @property array $payload
 * @property string|null $stored_file
 * @property int|null $deleted_by
 * @property string|null $owner_id
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read User|null $deleter
 */
class BinItem extends Model
{
    protected $fillable = ['kind', 'item_key', 'title', 'meta', 'payload', 'stored_file', 'deleted_by', 'owner_id', 'expires_at'];

    protected $casts = ['payload' => 'array', 'expires_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function daysLeft(): int
    {
        return max(0, (int) ceil(now()->diffInHours($this->expires_at, false) / 24));
    }
}
