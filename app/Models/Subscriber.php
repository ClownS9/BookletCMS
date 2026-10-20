<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * A reader who gets new posts by email. They count once they've confirmed,
 * and stop the moment they unsubscribe.
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property string $token
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $unsubscribed_at
 * @property string $source
 * @property int $emails_sent
 * @property \Illuminate\Support\Carbon|null $last_sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Subscriber extends Model
{
    use Notifiable;

    protected $fillable = ['email', 'name', 'token', 'confirmed_at', 'unsubscribed_at', 'source', 'emails_sent', 'last_sent_at'];

    protected $casts = ['confirmed_at' => 'datetime', 'unsubscribed_at' => 'datetime', 'last_sent_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (Subscriber $s) {
            $s->token ??= Str::random(48);
        });
    }

    /** Confirmed and still subscribed. */
    public function scopeActive(Builder $query): Builder
    {
        $query->whereNotNull('confirmed_at')->whereNull('unsubscribed_at');

        return $query;
    }

    public function isActive(): bool
    {
        return $this->confirmed_at !== null && $this->unsubscribed_at === null;
    }

    public function status(): string
    {
        return match (true) {
            $this->unsubscribed_at !== null => 'unsubscribed',
            $this->confirmed_at !== null => 'subscribed',
            default => 'waiting',
        };
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
