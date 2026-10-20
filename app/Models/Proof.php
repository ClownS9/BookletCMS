<?php

namespace App\Models;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A proof copy: a private link to an entry, drafts included, for someone
 * without an account to read (and leave notes on) before it's published.
 *
 * The link's secret is looked up by its sha256 only; the secret itself is
 * kept encrypted with APP_KEY, just so the link can be copied again.
 *
 * @property int $id
 * @property string $entry_id
 * @property string $token_hash
 * @property string $token
 * @property string|null $label
 * @property bool $notes_allowed
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property int $opens
 */
class Proof extends Model
{
    /** How long a link can last, in days, and what the choices are called. */
    public const LASTS = [3 => '3 days', 7 => 'a week', 30 => 'a month'];

    /** Open links per entry, at most. */
    public const MAX_OPEN = 20;

    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'notes_allowed' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    /**
     * Make a link. Returns the proof and its secret (the only time it's given
     * out in the clear, apart from copying the link again in the editor).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $entryId, ?User $by, int $days, ?string $label, bool $notes): array
    {
        $secret = Str::random(40);
        $proof = static::create([
            'entry_id' => $entryId,
            'token_hash' => hash('sha256', $secret),
            'token' => Crypt::encryptString($secret),
            'label' => $label !== null && trim($label) !== '' ? Str::limit(trim(strip_tags($label)), 80, '') : null,
            'notes_allowed' => $notes,
            'expires_at' => now()->addDays(array_key_exists($days, self::LASTS) ? $days : 7),
            'created_by' => $by?->id,
        ]);

        return [$proof, $secret];
    }

    /** The open proof for a link's secret, or null (unknown, stopped, or past its date). */
    public static function forSecret(string $secret): ?self
    {
        if (strlen($secret) < 20 || strlen($secret) > 100) {
            return null;
        }

        return static::query()->where('token_hash', hash('sha256', $secret))->open()->first();
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function url(): ?string
    {
        $secret = rescue(fn () => Crypt::decryptString($this->token), null, false);

        return $secret ? route('proof.show', $secret) : null;
    }

    public function entry(): ?Entry
    {
        return Booklet::entries()->find($this->entry_id);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProofNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ProofNote::class);
    }
}
