<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A password or API key kept encrypted with APP_KEY (stored exactly as
 * Laravel's "encrypted" cast does). If APP_KEY has changed since it was
 * saved, it reads as null instead of breaking every page or email that
 * touches it; the settings page then asks for it again.
 */
class Secret implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Crypt::encryptString((string) $value);
    }

    /** Saved, but unreadable with this APP_KEY. */
    public static function lost(Model $model, string $key): bool
    {
        return $model->getRawOriginal($key) !== null && $model->getRawOriginal($key) !== '' && $model->getAttribute($key) === null;
    }
}
