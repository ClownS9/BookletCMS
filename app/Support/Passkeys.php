<?php

namespace App\Support;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Passkeys (WebAuthn): signing in with Face ID, Touch ID, Windows Hello, a
 * phone or a security key instead of a password.
 *
 * Making one: options() hands the browser a one-time challenge; the device
 * makes a key pair, keeps the private half and sends back the public half,
 * which register() checks and keeps. Signing in: loginOptions() hands out a
 * fresh challenge, the device signs it, and verify() checks the signature
 * with the kept public half. The device asks for a face, finger or PIN each
 * time (user verification), so a passkey stands in for both the password and
 * the code from an app.
 *
 * Only "none" attestation is asked for: Booklet doesn't need to know which
 * maker's device it is, just that the same key comes back.
 */
class Passkeys
{
    /** COSE algorithms we accept: ES256 (most devices) and RS256 (Windows Hello). */
    public const ES256 = -7;

    public const RS256 = -257;

    /** Most passkeys one person may keep. */
    public const MAX = 20;

    /** How long a challenge stays good, in seconds. */
    protected const TTL = 300;

    protected const FLAG_UP = 0x01;

    protected const FLAG_UV = 0x04;

    protected const FLAG_AT = 0x40;

    /** The options for navigator.credentials.create(), for this person. */
    public static function options(User $user, Request $request): array
    {
        $challenge = self::challenge($request, 'register');

        return [
            'challenge' => $challenge,
            'rp' => ['name' => app(\App\Booklet\Site::class)->name() ?: 'Booklet', 'id' => $request->getHost()],
            'user' => ['id' => self::b64(self::handle($user)), 'name' => $user->email, 'displayName' => $user->name],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::ES256], ['type' => 'public-key', 'alg' => self::RS256]],
            'timeout' => self::TTL * 1000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            // Don't make a second passkey on a device that already has one for this person.
            'excludeCredentials' => $user->passkeys()->get()->map(fn (Passkey $p) => array_filter([
                'type' => 'public-key', 'id' => $p->credential_id, 'transports' => $p->transports ?: null,
            ]))->values()->all(),
        ];
    }

    /**
     * Check what the device sent back from create() and keep the passkey.
     *
     * @param  array<string, mixed>  $credential  {id, rawId, type, response: {clientDataJSON, attestationObject, transports?}}
     *
     * @throws PasskeyProblem
     */
    public static function register(User $user, array $credential, string $name, Request $request): Passkey
    {
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $clientJson = self::unb64((string) ($response['clientDataJSON'] ?? ''));
        self::checkClient($clientJson, 'webauthn.create', self::takeChallenge($request, 'register'), $request);

        $attestation = self::cbor(self::unb64((string) ($response['attestationObject'] ?? '')));
        if (! is_array($attestation) || ! is_string($attestation['authData'] ?? null)) {
            throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
        }
        $auth = self::authData($attestation['authData'], $request);
        if (! isset($auth['credentialId'], $auth['key'])) {
            throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
        }

        [$pem, $alg] = self::pem($auth['key']);
        $id = self::b64($auth['credentialId']);
        if (Passkey::query()->where('credential_id', $id)->exists()) {
            throw new PasskeyProblem('That passkey is already kept here.');
        }
        if ($user->passkeys()->count() >= self::MAX) {
            throw new PasskeyProblem('That’s as many passkeys as one person can keep. Remove one you no longer use first.');
        }

        $transports = array_values(array_filter((array) ($response['transports'] ?? []), fn ($t) => is_string($t) && preg_match('/^[a-z-]{2,20}$/', $t)));

        return $user->passkeys()->create([
            'name' => mb_substr(trim($name), 0, 80) ?: __('A passkey'),
            'credential_id' => $id,
            'public_key' => $pem,
            'alg' => $alg,
            'sign_count' => $auth['signCount'],
            'transports' => $transports ?: null,
        ]);
    }

    /** The options for navigator.credentials.get(): any passkey made for this site may answer. */
    public static function loginOptions(Request $request): array
    {
        return [
            'challenge' => self::challenge($request, 'login'),
            'rpId' => $request->getHost(),
            'timeout' => self::TTL * 1000,
            'userVerification' => 'required',
            'allowCredentials' => [],
        ];
    }

    /**
     * Check what the device signed. Gives back whose passkey it is.
     *
     * @param  array<string, mixed>  $credential  {id, rawId, type, response: {clientDataJSON, authenticatorData, signature, userHandle}}
     *
     * @throws PasskeyProblem
     */
    public static function verify(array $credential, Request $request): User
    {
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $clientJson = self::unb64((string) ($response['clientDataJSON'] ?? ''));
        self::checkClient($clientJson, 'webauthn.get', self::takeChallenge($request, 'login'), $request);

        $id = self::b64(self::unb64((string) ($credential['rawId'] ?? $credential['id'] ?? '')));
        $passkey = $id === '' ? null : Passkey::query()->where('credential_id', $id)->first();
        $user = $passkey?->user;
        if (! $passkey || ! $user) {
            throw new PasskeyProblem('This book doesn’t know that passkey. It may have been removed: sign in with your password instead.');
        }

        $handle = (string) ($response['userHandle'] ?? '');
        if ($handle !== '' && ! hash_equals(self::handle($user), self::unb64($handle))) {
            throw new PasskeyProblem('This book doesn’t know that passkey. It may have been removed: sign in with your password instead.');
        }

        $authBytes = self::unb64((string) ($response['authenticatorData'] ?? ''));
        $auth = self::authData($authBytes, $request);

        $signed = $authBytes.hash('sha256', $clientJson, true);
        $signature = self::unb64((string) ($response['signature'] ?? ''));
        // Both ES256 and RS256 sign a SHA-256 hash; ES256 signatures come DER-encoded, as openssl expects.
        if ($signature === '' || openssl_verify($signed, $signature, $passkey->public_key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new PasskeyProblem('That passkey’s answer didn’t check out. Try again.');
        }

        // A counter that goes backwards means the key may have been copied. Synced
        // passkeys (iCloud, Google) always say 0, which is fine.
        if (($auth['signCount'] > 0 || $passkey->sign_count > 0) && $auth['signCount'] <= $passkey->sign_count) {
            throw new PasskeyProblem('That passkey looks like a copy, so it wasn’t let in. Sign in with your password and remove it in Your account.');
        }

        $passkey->forceFill(['sign_count' => $auth['signCount'], 'last_used_at' => now()])->save();

        return $user;
    }

    /** The opaque id the device files a person's passkeys under (the same each time, nothing personal in it). */
    public static function handle(User $user): string
    {
        return hash('sha256', 'booklet-passkey|'.$user->getKey(), true);
    }

    /** A suggested name for a new passkey, from the browser: "Safari on iPhone". */
    public static function suggestName(Request $request): string
    {
        $d = Devices::describe((string) $request->userAgent());

        return __(':browser on :os', ['browser' => $d['browser'], 'os' => $d['os']]);
    }

    /** What to tell the person when the browser's side didn't work (the error's name from JavaScript). */
    public static function failure(string $name, bool $making = false): string
    {
        return match ($name) {
            // Browsers say only this, whether the person pressed Cancel, no passkey for this
            // site was found, or the device couldn't check a face, finger or PIN.
            'NotAllowedError', 'AbortError' => $making
                ? __('Your device didn’t make the passkey. It was cancelled, or the device couldn’t check it was you: on a computer, make sure Windows Hello or Touch ID is set up, or choose to use a phone instead.')
                : __('No passkey was used. Either it was cancelled, or this device has no passkey for this book yet: sign in with your password, then add one in Your account › Signing in.'),
            'InvalidStateError' => $making ? __('This device already keeps a passkey for you here.') : __('Something went wrong with the passkey. Try again.'),
            'insecure' => __('Passkeys only work on a secure (https) address.'),
            // Also what browsers say for an address that's numbers (127.0.0.1) or doesn't match the site.
            'SecurityError', 'numbers' => __('The browser refused this address for passkeys. They need https and a name, not numbers: on your own computer, open http://localhost instead of 127.0.0.1.'),
            'NotSupportedError', 'unsupported' => __('This browser can’t use passkeys. Try a recent Safari, Chrome, Edge or Firefox.'),
            default => __('Something went wrong with the passkey. Try again.'),
        };
    }

    // —— checks ——

    protected static function challenge(Request $request, string $for): string
    {
        $challenge = self::b64(random_bytes(32));
        session()->put('passkey.'.$for, ['challenge' => $challenge, 'until' => time() + self::TTL]);

        return $challenge;
    }

    /** The challenge we handed out (each one works once). */
    protected static function takeChallenge(Request $request, string $for): string
    {
        $kept = session()->pull('passkey.'.$for);
        if (! is_array($kept) || ($kept['until'] ?? 0) < time() || ! is_string($kept['challenge'] ?? null)) {
            throw new PasskeyProblem('That took a while. Try again.');
        }

        return $kept['challenge'];
    }

    protected static function checkClient(string $json, string $type, string $challenge, Request $request): void
    {
        $client = json_decode($json, true);
        if (! is_array($client) || ($client['type'] ?? null) !== $type) {
            throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
        }
        if (! hash_equals($challenge, (string) ($client['challenge'] ?? ''))) {
            throw new PasskeyProblem('That took a while. Try again.');
        }
        if (($client['origin'] ?? null) !== $request->getSchemeAndHttpHost()) {
            throw new PasskeyProblem('That passkey was made for a different address.');
        }
    }

    /**
     * Read the authenticator data: which site, that a person was there and
     * proved it's them, the counter, and (when a key is being made) the key.
     *
     * @return array{signCount: int, credentialId?: string, key?: array<int|string, mixed>}
     */
    protected static function authData(string $bytes, Request $request): array
    {
        if (strlen($bytes) < 37) {
            throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
        }
        if (! hash_equals(hash('sha256', $request->getHost(), true), substr($bytes, 0, 32))) {
            throw new PasskeyProblem('That passkey was made for a different address.');
        }
        $flags = ord($bytes[32]);
        if (! ($flags & self::FLAG_UP) || ! ($flags & self::FLAG_UV)) {
            throw new PasskeyProblem('Your device didn’t check it was you (face, fingerprint or PIN). Try again.');
        }
        $out = ['signCount' => (int) unpack('N', substr($bytes, 33, 4))[1]];

        if ($flags & self::FLAG_AT) {
            if (strlen($bytes) < 55) {
                throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
            }
            $length = (int) unpack('n', substr($bytes, 53, 2))[1];
            $out['credentialId'] = substr($bytes, 55, $length);
            $offset = 55 + $length;
            $key = self::cbor($bytes, $offset);
            if (strlen($out['credentialId']) !== $length || ! is_array($key)) {
                throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
            }
            $out['key'] = $key;
        }

        return $out;
    }

    /**
     * A COSE public key as PEM, with its algorithm.
     *
     * @param  array<int|string, mixed>  $key
     * @return array{0: string, 1: int}
     */
    protected static function pem(array $key): array
    {
        $kty = $key[1] ?? null;
        $alg = $key[3] ?? null;

        if ($kty === 2 && $alg === self::ES256 && ($key[-1] ?? null) === 1
            && is_string($key[-2] ?? null) && is_string($key[-3] ?? null) && strlen($key[-2]) === 32 && strlen($key[-3]) === 32) {
            // SubjectPublicKeyInfo for a P-256 point.
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$key[-2].$key[-3];

            return [self::armour($der), self::ES256];
        }

        if ($kty === 3 && $alg === self::RS256 && is_string($key[-1] ?? null) && is_string($key[-2] ?? null) && strlen($key[-1]) >= 256) {
            $rsa = self::der(0x30, self::derInt($key[-1]).self::derInt($key[-2]));
            $der = self::der(0x30, hex2bin('300d06092a864886f70d0101010500').self::der(0x03, "\x00".$rsa));

            return [self::armour($der), self::RS256];
        }

        throw new PasskeyProblem('That device offered a kind of key Booklet can’t use yet. Try another device or browser.');
    }

    protected static function armour(string $der): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            throw new PasskeyProblem('That device offered a kind of key Booklet can’t use yet. Try another device or browser.');
        }

        return $pem;
    }

    protected static function der(int $tag, string $body): string
    {
        $n = strlen($body);
        if ($n < 0x80) {
            return chr($tag).chr($n).$body;
        }
        $len = ltrim(pack('N', $n), "\x00");

        return chr($tag).chr(0x80 | strlen($len)).$len.$body;
    }

    protected static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) & 0x80) {
            $bytes = "\x00".$bytes;
        }

        return self::der(0x02, $bytes);
    }

    // —— CBOR: just enough to read what authenticators send ——

    /** Read one CBOR item from $bytes at $offset (moved past it). Byte and text strings both come back as PHP strings. */
    public static function cbor(string $bytes, int &$offset = 0, int $depth = 0): mixed
    {
        if ($depth > 16 || $offset >= strlen($bytes)) {
            throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
        }
        $first = ord($bytes[$offset++]);
        $major = $first >> 5;
        $info = $first & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.'),
            };
        }

        $n = match (true) {
            $info < 24 => $info,
            $info === 24 => ord(self::take($bytes, $offset, 1)),
            $info === 25 => (int) unpack('n', self::take($bytes, $offset, 2))[1],
            $info === 26 => (int) unpack('N', self::take($bytes, $offset, 4))[1],
            $info === 27 => (int) unpack('J', self::take($bytes, $offset, 8))[1],
            default => throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.'),
        };

        switch ($major) {
            case 0:
                return $n;
            case 1:
                return -1 - $n;
            case 2:
            case 3:
                return self::take($bytes, $offset, $n);
            case 4:
                $list = [];
                for ($i = 0; $i < $n; $i++) {
                    $list[] = self::cbor($bytes, $offset, $depth + 1);
                }

                return $list;
            case 5:
                $map = [];
                for ($i = 0; $i < $n; $i++) {
                    $k = self::cbor($bytes, $offset, $depth + 1);
                    if (! is_int($k) && ! is_string($k)) {
                        throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
                    }
                    $map[$k] = self::cbor($bytes, $offset, $depth + 1);
                }

                return $map;
            default: // 6: a tag, then the item it tags
                return self::cbor($bytes, $offset, $depth + 1);
        }
    }

    protected static function take(string $bytes, int &$offset, int $n): string
    {
        if ($n < 0 || $offset + $n > strlen($bytes)) {
            throw new PasskeyProblem('That passkey didn’t come back in a form Booklet understands.');
        }
        $out = substr($bytes, $offset, $n);
        $offset += $n;

        return $out;
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        $out = base64_decode(strtr($text, '-_', '+/'), true);

        return $out === false ? '' : $out;
    }
}
