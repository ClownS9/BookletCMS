<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Someone with an account on the public site: they sign in to read pages
 * opened to readers or to their groups, comment without typing their name,
 * and look after their own details. Never the admin: readers sign in with
 * their own guard ("reader"), and nothing in the admin looks at it.
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $state unconfirmed, waiting, active or blocked
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property array<string, mixed>|null $answers
 * @property int|null $invited_by
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Reader extends Authenticatable implements \Illuminate\Contracts\Translation\HasLocalePreference
{
    use Notifiable;

    public const STATES = ['unconfirmed', 'waiting', 'active', 'blocked'];

    protected $fillable = ['name', 'email', 'password', 'state', 'email_verified_at', 'approved_at', 'answers', 'invited_by', 'last_seen_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'answers' => 'array',
        ];
    }

    /**
     * Every group they're in or have asked to join.
     *
     * @return HasMany<GroupMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'member_id')->where('member_type', 'reader');
    }

    /** Handles of the groups they're in (not the ones they've only asked to join). */
    public function groupHandles(): array
    {
        return Group::query()->whereIn('id', $this->memberships()->where('state', 'in')->select('group_id'))->pluck('handle')->all();
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }

    /** Confirmed their email (they may still be waiting to be let in). */
    public function confirmed(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** @param  Builder<Reader>  $query */
    public function scopeState(Builder $query, string $state): void
    {
        $query->where('state', $state);
    }

    /** First name, for greetings. */
    public function firstName(): string
    {
        return (string) (preg_split('/\s+/', trim($this->name))[0] ?? $this->name);
    }

    /** Emails to readers are in the site's language, whoever sends them (an admin reading the book in another). */
    public function preferredLocale(): string
    {
        return app(\App\Booklet\Site::class)->language();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ReaderPasswordReset($token));
    }
}
