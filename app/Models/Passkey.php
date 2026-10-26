<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passkey someone signs in with: the public half of a key kept on their
 * phone, computer or security key.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $credential_id  base64url
 * @property string $public_key  PEM
 * @property int $alg  COSE algorithm (-7 ES256, -257 RS256)
 * @property int $sign_count
 * @property array|null $transports
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User|null $user
 */
class Passkey extends Model
{
    protected $fillable = ['user_id', 'name', 'credential_id', 'public_key', 'alg', 'sign_count', 'transports', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return [
            'alg' => 'integer',
            'sign_count' => 'integer',
            'transports' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
