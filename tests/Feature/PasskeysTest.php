<?php

namespace Tests\Feature;

use App\Livewire\Admin\AccountSecurity;
use App\Livewire\Auth\Login;
use App\Models\Passkey;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Support\Passkeys;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Passkeys: made on Your account › Signing in, used on the sign-in page.
 * The "device" here is a P-256 (or RSA) key made with openssl, answering
 * the way a real authenticator does.
 */
class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'password' => 'velvet-harbour-quince-42']);
    }

    public function test_adding_a_passkey_asks_for_the_password_then_keeps_the_public_key(): void
    {
        Notification::fake();
        $device = $this->device();

        $page = Livewire::actingAs($this->admin)->test(AccountSecurity::class)
            ->assertSee('Passkeys')->assertSee('Add a passkey')
            ->call('startPasskey')->assertSet('asking', 'passkey')->assertSet('addingPasskey', false)
            // Nothing to make before the password is in.
            ->call('passkeyOptions')->assertSet('addingPasskey', false);
        $this->assertNull(session('passkey.register'));

        $page->set('password', 'wrong')->call('answer')->assertHasErrors('password')->assertSet('addingPasskey', false)
            ->set('password', 'velvet-harbour-quince-42')->call('answer')->assertHasNoErrors()
            ->assertSet('addingPasskey', true)->set('passkeyName', 'Nur’s laptop')
            ->call('passkeyOptions');

        $options = session('passkey.register');
        $this->assertIsArray($options);
        $page->call('addPasskey', $this->made($device, $options['challenge']))->assertHasNoErrors()
            ->assertSet('addingPasskey', false)->assertSee('Nur’s laptop')->assertDispatched('stamp');

        $passkey = Passkey::query()->sole();
        $this->assertSame($this->admin->id, $passkey->user_id);
        $this->assertSame(Passkeys::b64($device['id']), $passkey->credential_id);
        $this->assertSame(Passkeys::ES256, $passkey->alg);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $passkey->public_key);
        $this->assertSame(['internal', 'hybrid'], $passkey->transports);
        Notification::assertSentTo($this->admin, SecurityNotice::class, fn ($n) => $n->kind === 'passkey' && $n->detail === 'Nur’s laptop');

        // The next one skips the password (typed a moment ago) and won't take the same device twice.
        $page->call('startPasskey')->assertSet('asking', null)->assertSet('addingPasskey', true)->call('passkeyOptions');
        $again = Passkeys::options($this->admin, request());
        $this->assertSame($passkey->credential_id, $again['excludeCredentials'][0]['id']);
        $page->call('addPasskey', $this->made($device, session('passkey.register')['challenge']))->assertHasErrors('passkey');
        $this->assertSame(1, Passkey::query()->count());
    }

    public function test_a_passkey_signs_in_on_its_own_even_with_two_step_sign_in_on(): void
    {
        $device = $this->device();
        $this->keep($device);
        TwoFactor::enable($this->admin, TwoFactor::newSecret());

        $login = Livewire::test(Login::class)->assertSee('Sign in with a passkey')->call('passkeyOptions');
        $challenge = session('passkey.login')['challenge'];
        $login->call('passkeySignIn', $this->signed($device, $challenge, 1))->assertHasNoErrors()->assertRedirect(route('admin.contents'));

        $this->assertAuthenticatedAs($this->admin);
        $passkey = Passkey::query()->sole();
        $this->assertSame(1, $passkey->sign_count);
        $this->assertNotNull($passkey->last_used_at);
    }

    public function test_answers_that_dont_check_out_are_turned_away(): void
    {
        $device = $this->device();
        $this->keep($device, signCount: 5);

        $try = function (callable $answer, string $why, string $says) {
            auth()->logout();
            $login = Livewire::test(Login::class)->call('passkeyOptions');
            $login->call('passkeySignIn', $answer(session('passkey.login')['challenge']))->assertHasErrors('passkey')->assertSee($says);
            $this->assertFalse(auth()->check(), $why);
        };

        $try(fn ($c) => $this->signed($device, 'not-the-challenge', 6), 'a different challenge', 'That took a while.');
        $try(fn ($c) => $this->signed($device, $c, 6, origin: 'https://evil.example'), 'another address', 'made for a different address');
        $try(fn ($c) => $this->signed($device, $c, 6, flags: 0x01), 'no face, finger or PIN', 'didn’t check it was you');
        $try(fn ($c) => $this->signed($device, $c, 5), 'a counter that didn’t go up (a copy)', 'looks like a copy');
        $try(fn ($c) => $this->signed($this->device($device['id']), $c, 6), 'signed by a different key', 'didn’t check out');
        $try(function ($c) use ($device) {
            $a = $this->signed($device, $c, 6);
            $a['response']['userHandle'] = Passkeys::b64(random_bytes(32));

            return $a;
        }, 'someone else’s handle', 'doesn’t know that passkey');

        // A challenge works once.
        $login = Livewire::test(Login::class)->call('passkeyOptions');
        $answer = $this->signed($device, session('passkey.login')['challenge'], 7);
        $login->call('passkeySignIn', $answer)->assertHasNoErrors();
        $this->assertAuthenticatedAs($this->admin);
        auth()->logout();
        Livewire::test(Login::class)->call('passkeySignIn', $answer)->assertHasErrors('passkey');
        $this->assertGuest();

        // A removed passkey no longer opens the book; nobody removes someone else's.
        $other = User::factory()->create(['role' => 'editor']);
        $passkey = Passkey::query()->sole();
        Livewire::actingAs($other)->test(AccountSecurity::class)->call('removePasskey', $passkey->id);
        $this->assertModelExists($passkey);
        Livewire::actingAs($this->admin)->test(AccountSecurity::class)->assertSee($passkey->name)->call('removePasskey', $passkey->id);
        $this->assertModelMissing($passkey);
        auth()->logout();
        $login = Livewire::test(Login::class)->call('passkeyOptions');
        $login->call('passkeySignIn', $this->signed($device, session('passkey.login')['challenge'], 8))->assertHasErrors('passkey');
        $this->assertGuest();
    }

    public function test_windows_hello_keys_rsa_work_too_and_the_browsers_troubles_are_explained(): void
    {
        $device = $this->device(rsa: true);
        $this->actingAs($this->admin);
        session()->put('passkey.register', ['challenge' => 'abc', 'until' => time() + 60]);
        $passkey = Passkeys::register($this->admin, $this->made($device, 'abc'), 'Windows Hello', request());
        $this->assertSame(Passkeys::RS256, $passkey->alg);
        auth()->logout();

        $login = Livewire::test(Login::class)->call('passkeyOptions');
        $login->call('passkeySignIn', $this->signed($device, session('passkey.login')['challenge'], 0))->assertHasNoErrors();
        $this->assertAuthenticatedAs($this->admin);
        auth()->logout();

        Livewire::test(Login::class)->call('passkeyFailed', 'NotAllowedError')->assertSee('Cancelled, or your device didn’t answer in time.');
        $this->assertStringContainsString('https', Passkeys::failure('insecure'));

        // Every reason a passkey is turned away has its Malay.
        $ms = json_decode((string) file_get_contents(lang_path('ms.json')), true);
        preg_match_all("/PasskeyProblem\\('([^']+)'\\)/", (string) file_get_contents(app_path('Support/Passkeys.php')), $m);
        $this->assertNotEmpty($m[1]);
        $this->assertSame([], array_values(array_diff(array_unique($m[1]), array_keys($ms))));
    }

    // —— a pretend authenticator ——

    /** @return array{id: string, key: \OpenSSLAsymmetricKey, cose: string, rsa: bool} */
    protected function device(?string $id = null, bool $rsa = false): array
    {
        $key = openssl_pkey_new($rsa ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
            : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key);
        $cose = $rsa
            ? $this->cbor([1 => 3, 3 => -257, -1 => ['b' => $d['rsa']['n']], -2 => ['b' => $d['rsa']['e']]])
            : $this->cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['b' => str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)], -3 => ['b' => str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)]]);

        return ['id' => $id ?? random_bytes(16), 'key' => $key, 'cose' => $cose, 'rsa' => $rsa];
    }

    protected function keep(array $device, int $signCount = 0): void
    {
        $this->actingAs($this->admin);
        session()->put('passkey.register', ['challenge' => 'abc', 'until' => time() + 60]);
        $passkey = Passkeys::register($this->admin, $this->made($device, 'abc'), 'Phone', request());
        $passkey->forceFill(['sign_count' => $signCount])->save();
        auth()->logout();
    }

    /** The address the test requests go to (APP_URL), as a browser would see it. */
    protected function origin(): string
    {
        return request()->getSchemeAndHttpHost();
    }

    protected function host(): string
    {
        return request()->getHost();
    }

    protected function client(string $type, string $challenge, ?string $origin = null): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? $this->origin(), 'crossOrigin' => false]);
    }

    protected function made(array $device, string $challenge): array
    {
        $auth = hash('sha256', $this->host(), true).chr(0x45).pack('N', 0).str_repeat("\0", 16)
            .pack('n', strlen($device['id'])).$device['id'].$device['cose'];
        $attestation = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['b' => $auth]]);

        return ['id' => Passkeys::b64($device['id']), 'rawId' => Passkeys::b64($device['id']), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => Passkeys::b64($this->client('webauthn.create', $challenge)),
            'attestationObject' => Passkeys::b64($attestation),
            'transports' => ['internal', 'hybrid', '<script>'],
        ]];
    }

    protected function signed(array $device, string $challenge, int $count, ?string $origin = null, int $flags = 0x05): array
    {
        $auth = hash('sha256', $this->host(), true).chr($flags).pack('N', $count);
        $client = $this->client('webauthn.get', $challenge, $origin);
        openssl_sign($auth.hash('sha256', $client, true), $signature, $device['key'], OPENSSL_ALGO_SHA256);

        return ['id' => Passkeys::b64($device['id']), 'rawId' => Passkeys::b64($device['id']), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => Passkeys::b64($client),
            'authenticatorData' => Passkeys::b64($auth),
            'signature' => Passkeys::b64($signature),
            'userHandle' => Passkeys::b64(Passkeys::handle($this->admin)),
        ]];
    }

    /** A small CBOR writer: ints, text, ['b' => bytes], lists and maps. */
    protected function cbor(mixed $v): string
    {
        $head = function (int $major, int $n): string {
            return match (true) {
                $n < 24 => chr(($major << 5) | $n),
                $n < 256 => chr(($major << 5) | 24).chr($n),
                $n < 65536 => chr(($major << 5) | 25).pack('n', $n),
                default => chr(($major << 5) | 26).pack('N', $n),
            };
        };
        if (is_int($v)) {
            return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        }
        if (is_string($v)) {
            return $head(3, strlen($v)).$v;
        }
        if (is_array($v) && array_keys($v) === ['b']) {
            return $head(2, strlen($v['b'])).$v['b'];
        }
        if (is_array($v) && array_is_list($v) && $v !== []) {
            return $head(4, count($v)).implode('', array_map(fn ($x) => $this->cbor($x), $v));
        }
        $out = $head(5, count($v));
        foreach ($v as $k => $x) {
            $out .= $this->cbor($k).$this->cbor($x);
        }

        return $out;
    }
}
