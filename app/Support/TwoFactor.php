<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Two-step sign-in with an authenticator app (Google Authenticator, 1Password,
 * Authy, Microsoft Authenticator…): the standard 6-digit codes that change
 * every 30 seconds (TOTP, RFC 6238), plus single-use recovery codes.
 *
 * The secret and recovery codes are kept encrypted on the user. A code can't
 * be used twice, and a little clock drift (one step either way) is allowed.
 */
class TwoFactor
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    public const WINDOW = 1;

    public const RECOVERY_CODES = 10;

    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // The maths (no Laravel needed) -------------------------------------------------

    /** A new secret: 160 random bits, in base32 as authenticator apps expect. */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The code for a moment in time (now, unless given). */
    public static function code(string $secret, ?int $at = null, int $digits = self::DIGITS): string
    {
        $step = intdiv($at ?? time(), self::PERIOD);

        return self::codeForStep($secret, $step, $digits);
    }

    public static function codeForStep(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $number = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Which time step a code belongs to (within the allowed drift), or null.
     */
    public static function matchStep(string $secret, string $code, ?int $at = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = intdiv($at ?? time(), self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::codeForStep($secret, $now + $i), $code)) {
                return $now + $i;
            }
        }

        return null;
    }

    /** What the authenticator app scans. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /** @return string[] like "k7f2-9qmx" */
    public static function newRecoveryCodes(): array
    {
        return array_map(
            fn () => strtolower(Str::random(4).'-'.Str::random(4)),
            range(1, self::RECOVERY_CODES),
        );
    }

    // People ------------------------------------------------------------------------

    public static function on(User $user): bool
    {
        return $user->two_factor_secret && $user->two_factor_confirmed_at;
    }

    public static function secretOf(User $user): ?string
    {
        return $user->two_factor_secret ? rescue(fn () => decrypt($user->two_factor_secret), null, false) : null;
    }

    /** @return string[] */
    public static function recoveryCodesOf(User $user): array
    {
        return $user->two_factor_recovery_codes
            ? (array) rescue(fn () => json_decode(decrypt($user->two_factor_recovery_codes), true), [], false)
            : [];
    }

    /** Switch it on, once they've proved their app works. Returns the recovery codes to show once. */
    public static function enable(User $user, string $secret): array
    {
        $codes = self::newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    public static function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /** @return string[] */
    public static function regenerateRecoveryCodes(User $user): array
    {
        $codes = self::newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode($codes))])->save();

        return $codes;
    }

    /**
     * Does this code (from the app, or a recovery code) let them in?
     * App codes can't be used twice; recovery codes are crossed off.
     *
     * @return 'app'|'recovery'|null
     */
    public static function check(User $user, string $code): ?string
    {
        $secret = self::secretOf($user);
        if (! $secret) {
            return null;
        }

        $step = self::matchStep($secret, $code);
        if ($step !== null) {
            // Each code works once: remember the newest step used.
            $key = 'booklet.2fa.step.'.$user->id;
            if ((int) Cache::get($key, -1) >= $step) {
                return null;
            }
            Cache::put($key, $step, now()->addMinutes(5));

            return 'app';
        }

        $typed = strtolower(trim($code));
        $codes = self::recoveryCodesOf($user);
        foreach ($codes as $i => $recovery) {
            if (hash_equals($recovery, $typed)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(array_values($codes)))])->save();

                return 'recovery';
            }
        }

        return null;
    }
}
