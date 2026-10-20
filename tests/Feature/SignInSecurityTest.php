<?php

namespace Tests\Feature;

use App\Livewire\Admin\AccountSecurity;
use App\Livewire\Admin\People;
use App\Livewire\Auth\Login;
use App\Models\User;
use App\Support\Devices;
use App\Support\TwoFactor;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two-step sign-in (authenticator app codes, recovery codes) and the list of
 * devices you're signed in on.
 */
class SignInSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Booklet\Facades\Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin', 'email' => 'nur@example.com', 'password' => 'right-password']);
    }

    public function test_the_codes_match_the_standard(): void
    {
        // RFC 6238, appendix B (SHA1)
        $secret = TwoFactor::base32Encode('12345678901234567890');
        $this->assertSame('94287082', TwoFactor::code($secret, 59, 8));
        $this->assertSame('14050471', TwoFactor::code($secret, 1111111111, 8));
        $this->assertSame('65353130', TwoFactor::code($secret, 20000000000, 8));
    }

    public function test_turning_it_on_needs_a_working_code(): void
    {
        $this->actingAs($this->nur);

        $c = Livewire::test(AccountSecurity::class)->call('start');
        $secret = $c->get('setupSecret');
        $this->assertNotEmpty($secret);

        $c->set('setupCode', '000000')->call('confirm')->assertHasErrors('setupCode');
        $this->assertFalse(TwoFactor::on($this->nur->fresh()));

        $c->set('setupCode', TwoFactor::code($secret))->call('confirm')->assertHasNoErrors();
        $this->assertTrue(TwoFactor::on($this->nur->fresh()));
        $this->assertCount(10, $c->get('freshCodes'));
        $this->assertStringNotContainsString($secret, (string) $this->nur->fresh()->two_factor_secret, 'kept encrypted');
    }

    public function test_signing_in_asks_for_the_code_before_opening_the_book(): void
    {
        TwoFactor::enable($this->nur, self::SECRET);

        $login = Livewire::test(Login::class)
            ->set('email', 'nur@example.com')->set('password', 'right-password')->call('login')
            ->assertSet('mode', 'code');
        $this->assertGuest();

        $login->set('code', '000000')->call('verifyCode')->assertHasErrors('code');
        $this->assertGuest();

        $login->set('code', TwoFactor::code(self::SECRET))->call('verifyCode')->assertRedirect(route('admin.contents'));
        $this->assertAuthenticatedAs($this->nur);
    }

    public function test_a_wrong_password_never_reaches_the_code_step(): void
    {
        TwoFactor::enable($this->nur, self::SECRET);

        Livewire::test(Login::class)
            ->set('email', 'nur@example.com')->set('password', 'wrong')->call('login')
            ->assertHasErrors('email')->assertSet('mode', 'signin');
    }

    public function test_a_code_works_once_and_recovery_codes_are_crossed_off(): void
    {
        $codes = TwoFactor::enable($this->nur, self::SECRET);
        $code = TwoFactor::code(self::SECRET);

        $this->assertSame('app', TwoFactor::check($this->nur, $code));
        $this->assertNull(TwoFactor::check($this->nur, $code), 'not twice');

        $this->assertSame('recovery', TwoFactor::check($this->nur->fresh(), $codes[0]));
        $this->assertNull(TwoFactor::check($this->nur->fresh(), $codes[0]), 'each recovery code once');
        $this->assertCount(9, TwoFactor::recoveryCodesOf($this->nur->fresh()));
    }

    public function test_turning_it_off_needs_your_password(): void
    {
        TwoFactor::enable($this->nur, self::SECRET);
        $this->actingAs($this->nur);

        Livewire::test(AccountSecurity::class)
            ->call('ask', 'off')->set('password', 'nope')->call('answer')->assertHasErrors('password');
        $this->assertTrue(TwoFactor::on($this->nur->fresh()));

        Livewire::test(AccountSecurity::class)
            ->call('ask', 'off')->set('password', 'right-password')->call('answer')->assertHasNoErrors();
        $this->assertFalse(TwoFactor::on($this->nur->fresh()));
    }

    public function test_an_admin_can_reset_someone_who_lost_their_phone_and_stays_themselves(): void
    {
        $hana = User::factory()->create(['role' => 'writer']);
        TwoFactor::enable($hana, self::SECRET);
        $this->actingAs($this->nur);

        Livewire::test(People::class)->call('pick', $hana->id)->call('resetTwoFactor');

        $this->assertFalse(TwoFactor::on($hana->fresh()));
        $this->assertAuthenticatedAs($this->nur);

        // Writers can't.
        $this->actingAs($hana);
        TwoFactor::enable($this->nur, self::SECRET);
        Livewire::test(People::class)->call('pick', $this->nur->id)->call('resetTwoFactor')->assertForbidden();
    }

    public function test_devices_are_listed_and_can_be_signed_out(): void
    {
        config(['session.driver' => 'database']);
        $this->actingAs($this->nur);
        $here = session()->getId();
        $rows = [
            [$here, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Chrome/129.0 Safari/537.36'],
            ['phone-session', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1'],
            ['old-laptop', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/131.0'],
        ];
        foreach ($rows as [$id, $agent]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $this->nur->id, 'ip_address' => '203.0.113.7', 'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->timestamp]);
        }

        $devices = Devices::for($this->nur);
        $this->assertTrue($devices->first()['current']);
        $this->assertSame('Safari on iPhone', $devices->firstWhere('id', 'phone-session')['browser'].' on '.$devices->firstWhere('id', 'phone-session')['os']);
        $this->assertSame('phone', $devices->firstWhere('id', 'phone-session')['kind']);

        $token = $this->nur->fresh()->remember_token;
        $this->assertTrue(Devices::signOut($this->nur, 'phone-session'));
        $this->assertNotSame($token, $this->nur->fresh()->remember_token, '“Keep me signed in” stops working there too');
        $this->assertFalse(Devices::signOut($this->nur, $here), 'never this one');

        $this->assertSame(1, Devices::signOutOthers($this->nur));
        $this->assertSame([$here], DB::table('sessions')->where('user_id', $this->nur->id)->pluck('id')->all());
    }
}
