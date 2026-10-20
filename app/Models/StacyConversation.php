<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $title
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StacyMessage> $messages
 */
class StacyConversation extends Model
{
    protected $fillable = ['user_id', 'title'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<StacyMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(StacyMessage::class, 'conversation_id')->orderBy('id');
    }
}
