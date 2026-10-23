<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A key for the read-only content API (Settings › Content API). The key is
 * shown once when it's made; only its SHA-256 fingerprint is kept.
 *
 * @property int $id
 * @property string $name
 * @property string $key_hash
 * @property string $last4
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property int $uses
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class ApiKey extends Model
{
    /** Keys start with this, so they're easy to recognise (and to find if one leaks into code). */
    public const PREFIX = 'bk_';

    protected $guarded = ['id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * Make a key. Returns the model and the key itself (the only time it's given out).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?User $by): array
    {
        $key = self::PREFIX.Str::random(40);
        $model = static::create([
            'name' => Str::limit(trim(strip_tags($name)), 80, '') ?: 'A key',
            'key_hash' => hash('sha256', $key),
            'last4' => substr($key, -4),
            'created_by' => $by?->id,
        ]);

        return [$model, $key];
    }

    public static function findActive(string $key): ?self
    {
        if (strlen($key) < 20 || strlen($key) > 100) {
            return null;
        }

        return static::query()->where('key_hash', hash('sha256', $key))->whereNull('revoked_at')->first();
    }

    /** Count a use (the time at most once a minute, to keep writes down). */
    public function used(): void
    {
        $changes = ['uses' => $this->uses + 1];
        if (! $this->last_used_at || $this->last_used_at->lt(now()->subMinute())) {
            $changes['last_used_at'] = now();
        }
        $this->forceFill($changes)->saveQuietly();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
