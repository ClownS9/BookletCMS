<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone in a group: a reader or a person from the book (member_type
 * "reader" or "user"), in, or asked to join and waiting.
 *
 * @property int $id
 * @property int $group_id
 * @property string $member_type
 * @property int $member_id
 * @property string $state in or asked
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class GroupMember extends Model
{
    protected $fillable = ['group_id', 'member_type', 'member_id', 'state'];

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** The reader or person it is. */
    public function who(): Reader|User|null
    {
        return $this->member_type === 'reader' ? Reader::find($this->member_id) : User::find($this->member_id);
    }
}
