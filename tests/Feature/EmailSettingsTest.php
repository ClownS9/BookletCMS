<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\EmailSettings;
use App\Models\MailSetting;
use App\Models\User;
use App\Support\Backups;
use App\Support\MailSettings;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Settings › Email: the mail server is set in the admin, not in .env.
 */
class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'nur@example.com']);
    }

    protected function form()
    {
        return Livewire::actingAs($this->admin)->test(EmailSettings::class);
    }

    public function test_only_admins_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get(route('admin.settings.email'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.settings.email'))->assertOk()->assertSee('Your email service');
    }

    public function test_picking_a_service_fills_in_the_server(): void
    {
        $this->form()
            ->call('pick', 'sendgrid')->assertSet('host', 'smtp.sendgrid.net')->assertSet('port', '587')->assertSet('username', 'apikey')
            ->call('pick', 'gmail')->assertSet('host', 'smtp.gmail.com')->assertSet('username', '')
            ->set('port', '465')->assertSet('security', 'ssl');
    }

    public function test_the_password_is_kept_encrypted_and_never_shown_again(): void
    {
        $this->form()
            ->set('enabled', true)->call('pick', 'gmail')
            ->set('username', 'nur@gmail.com')->set('password', 'abcd efgh ijkl mnop')
            ->set('fromAddress', 'nur@gmail.com')->set('fromName', 'Kopi & Kuih')
            ->call('save')->assertHasNoErrors()->assertSet('password', '')
            ->assertDontSee('abcd efgh ijkl mnop');

        $raw = DB::table('mail_settings')->value('password');
        $this->assertStringNotContainsString('abcd', (string) $raw);
        $this->assertSame('abcd efgh ijkl mnop', MailSetting::first()->password);
        $this->assertArrayNotHasKey('password', MailSetting::first()->toArray());

        // Saving again with the box empty keeps it.
        $this->form()->set('fromName', 'Kopi')->call('save');
        $this->assertSame('abcd efgh ijkl mnop', MailSetting::first()->password);

        $this->assertArrayNotHasKey('mail_settings', Backups::TABLES, 'never in a backup');
    }

    public function test_once_on_every_email_uses_it_instead_of_env(): void
    {
        $this->assertSame('off', MailSettings::status()['state'], 'phpunit sends to an array, like the log');

        MailSetting::create(['enabled' => true, 'service' => 'other', 'host' => 'mail.example.com', 'port' => 465, 'security' => 'ssl',
            'username' => 'u', 'password' => 'p', 'from_address' => 'hello@example.com', 'from_name' => 'Kopi & Kuih']);

        $this->app->forgetInstance('mail.manager');
        $this->app->make('mail.manager'); // the first email of a request

        $this->assertSame(MailSettings::MAILER, config('mail.default'));
        $this->assertSame('mail.example.com', config('mail.mailers.booklet.host'));
        $this->assertSame('smtps', config('mail.mailers.booklet.scheme'));
        $this->assertSame('hello@example.com', config('mail.from.address'));
        $this->assertSame('on', MailSettings::status()['state']);
    }

    public function test_switched_off_leaves_env_alone(): void
    {
        MailSetting::create(['enabled' => false, 'host' => 'mail.example.com', 'port' => 587, 'from_address' => 'hello@example.com']);
        $before = config('mail.default');
        MailSettings::apply();
        $this->assertSame($before, config('mail.default'));
    }

    public function test_starttls_is_required_and_none_never_tries_it(): void
    {
        $tls = MailSettings::config(new MailSetting(['host' => 'h', 'port' => 587, 'security' => 'tls', 'from_address' => 'a@b.c']));
        $this->assertTrue($tls['require_tls']);
        $this->assertSame('smtp', $tls['scheme']);

        $none = MailSettings::config(new MailSetting(['host' => 'h', 'port' => 25, 'security' => 'none', 'from_address' => 'a@b.c']));
        $this->assertFalse($none['auto_tls']);
        $this->assertArrayNotHasKey('require_tls', $none);
    }

    public function test_bad_details_are_caught_before_saving(): void
    {
        $this->form()->set('enabled', true)->set('host', 'https://smtp.example.com/')->set('port', '99999')->set('fromAddress', 'nope')
            ->call('save')->assertHasErrors(['host', 'port', 'fromAddress']);
        $this->assertSame(0, MailSetting::count());
    }

    public function test_a_failed_test_explains_itself(): void
    {
        // Nothing listens on port 1 here: refused at once.
        $this->form()->call('pick', 'other')->set('host', '127.0.0.1')->set('port', '1')->set('security', 'none')
            ->set('fromAddress', 'hello@example.com')
            ->call('sendTest')->assertSet('noticeIsError', true)->assertSee('Couldn’t reach 127.0.0.1:1');

        $s = new MailSetting(['service' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'from_address' => 'a@b.c']);
        $this->assertStringContainsString('app password', MailSettings::explain(new RuntimeException('Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted."'), $s));
    }

    public function test_a_password_saved_under_an_old_app_key_doesnt_break_email(): void
    {
        MailSetting::create(['enabled' => true, 'service' => 'other', 'host' => 'mail.example.com', 'port' => 587, 'security' => 'tls',
            'username' => 'u', 'password' => 'p', 'from_address' => 'hello@example.com']);
        // As if APP_KEY changed since: the stored value no longer decrypts.
        DB::table('mail_settings')->update(['password' => 'eyJpdiI6IndyaXR0ZW4tdW5kZXItYW4tb2xkLWtleSJ9']);

        $record = MailSetting::first();
        $this->assertNull($record->password);
        $this->assertTrue($record->passwordLost());
        $this->assertFalse($record->isReady());

        $before = config('mail.default');
        MailSettings::apply(); // falls back to .env instead of throwing
        $this->assertSame($before, config('mail.default'));
        $this->assertStringContainsString('APP_KEY changed', MailSettings::status()['text']);

        $this->form()->assertSee('can’t be read any more')->call('save')->assertHasErrors('password')
            ->set('password', 'p2')->call('save')->assertHasNoErrors();
        $this->assertSame('p2', MailSetting::first()->password);
        $this->assertFalse(MailSetting::first()->passwordLost());
    }

    public function test_stacys_keys_survive_a_new_app_key_the_same_way(): void
    {
        \App\Models\AiProvider::create(['service' => 'anthropic', 'enabled' => true, 'api_key' => 'sk-test', 'default_model' => 'm']);
        DB::table('ai_providers')->update(['api_key' => 'not-decryptable']);

        $provider = \App\Models\AiProvider::first();
        $this->assertNull($provider->api_key);
        $this->assertTrue($provider->keyLost());
        $this->assertFalse($provider->isReady());
        $this->actingAs($this->admin)->get(route('admin.settings.stacy'))->assertOk()->assertSee('can’t be read any more');
    }
}
