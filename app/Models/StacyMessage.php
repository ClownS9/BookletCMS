<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a conversation. `meta` holds the links shown under Stacy's
 * answer, a change waiting for "Yes", and which model answered.
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property array|null $meta
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read StacyConversation|null $conversation
 */
class StacyMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'role', 'content', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<StacyConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(StacyConversation::class, 'conversation_id');
    }
}
