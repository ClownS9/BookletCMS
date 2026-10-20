<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A folded page corner: a bookmark to a page of the admin, for one person.
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property int $user_id
 * @property string $url
 * @property string $title
 * @property string|null $chapter
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read User|null $user
 */
class DogEar extends Model
{
    protected $fillable = ['user_id', 'url', 'title', 'chapter'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
