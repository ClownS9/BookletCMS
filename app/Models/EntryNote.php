<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sticky note in the margin of an entry. Only the team sees it.
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $entry_id
 * @property int|null $user_id
 * @property string $body
 * @property string $tone
 * @property \Illuminate\Support\Carbon|null $done_at
 * @property int|null $done_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read User|null $user
 * @property-read User|null $doneBy
 */
class EntryNote extends Model
{
    public const TONES = ['sun', 'mint', 'sky', 'rose', 'lilac'];

    protected $fillable = ['entry_id', 'user_id', 'body', 'tone', 'done_at', 'done_by'];

    protected $casts = ['done_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    /** Open notes per entry id, for lists. */
    public static function openCounts(): array
    {
        return rescue(fn () => static::query()->whereNull('done_at')->selectRaw('entry_id, count(*) as n')->groupBy('entry_id')->pluck('n', 'entry_id')->all(), [], false);
    }
}
