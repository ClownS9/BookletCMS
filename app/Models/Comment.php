<?php

namespace App\Models;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $entry_id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string $author_name
 * @property string|null $author_email
 * @property string $body
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $notify
 * @property \Illuminate\Support\Carbon|null $alerted_at
 *
 * @property-read Comment|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Comment> $replies
 * @property-read User|null $user
 */
class Comment extends Model
{
    protected $fillable = ['entry_id', 'parent_id', 'user_id', 'author_name', 'author_email', 'body', 'status', 'notify'];

    protected $casts = ['notify' => 'boolean', 'alerted_at' => 'datetime'];

    protected static function booted(): void
    {
        // A reply that's out (posted approved, or approved later): tell whoever asked to hear.
        static::saved(function (Comment $comment) {
            if ($comment->parent_id && $comment->status === 'approved' && ! $comment->alerted_at) {
                \App\Support\ReplyAlerts::send($comment);
            }
        });
    }

    /** The first note of the conversation this one belongs to. */
    public function root(): Comment
    {
        return $this->parent_id ? ($this->parent ?? $this) : $this;
    }

    public function entry(): ?Entry
    {
        return Booklet::entries()->find($this->entry_id);
    }

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /** @return HasMany<Comment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
