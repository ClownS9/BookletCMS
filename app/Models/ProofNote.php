<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note left on a proof copy: who (as they gave their name), the words
 * they picked out (if any), and what they said.
 *
 * @property int $id
 * @property int $proof_id
 * @property string $entry_id
 * @property string $name
 * @property string|null $quote
 * @property string $body
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class ProofNote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Proof, $this> */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(Proof::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
