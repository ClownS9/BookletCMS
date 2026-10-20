<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Livewire\Admin\Account;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Support\PasswordStrength;
use App\Support\SafeUpload;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Passwords that are hard to guess (the same reckoning as the browser's
 * meter), sign-in details that only change with the password, and uploads
 * that can't be turned against the site.
 */
class PasswordAndUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah', 'email' => 'nur.aisyah@example.com', 'password' => 'old-kettle-orbit-9']);
        Notification::fake();
    }

    public function test_the_strength_reckoning(): void
    {
        // The same passwords as the browser's meter is checked against.
        $cases = [
            'password' => 0, 'Password1!' => 0, 'P@ssw0rd' => 0, 'iloveyou2024' => 0, 'aaaaaaaaaaaa' => 0,
            'abcdefghijkl' => 0, 'B00klet-Circas' => 0, 'a-long-password' => 2, 'MyDog&Me2019' => 3,
            'kettle-orbit-lantern-7' => 4, 'Selamat pagi Brunei' => 4, 'xK9#mQ2$vL' => 4,
        ];
        foreach ($cases as $password => $score) {
            $this->assertSame($score, PasswordStrength::check($password)['score'], $password);
        }

        $this->assertSame(0, PasswordStrength::check('nuraisyah2026', ['Nur Aisyah'])['score']);
        $this->assertSame(0, PasswordStrength::check('nur.aisyah@example.com', ['nur.aisyah@example.com'])['score'], 'your own email');
        $this->assertNotNull(PasswordStrength::problem('Mango7!x'), 'too short');
        $this->assertNotNull(PasswordStrength::problem(str_repeat('kettle-orbit ', 6)), 'over 72 bytes would be cut short');
        $this->assertNull(PasswordStrength::problem('kettle-orbit-lantern-7'));
    }

    public function test_changing_your_password(): void
    {
        $this->actingAs($this->nur);

        Livewire::test(Account::class)
            ->set('currentPassword', 'old-kettle-orbit-9')->set('newPassword', 'password123')->set('newPassword_confirmation', 'password123')
            ->call('savePassword')->assertHasErrors('newPassword');

        Livewire::test(Account::class)
            ->set('currentPassword', 'old-kettle-orbit-9')->set('newPassword', 'old-kettle-orbit-9')->set('newPassword_confirmation', 'old-kettle-orbit-9')
            ->call('savePassword')->assertHasErrors('newPassword');

        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'the-old-laptop', 'user_id' => $this->nur->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => now()->timestamp]);

        Livewire::test(Account::class)
            ->set('currentPassword', 'old-kettle-orbit-9')->set('newPassword', 'lantern-mango-river-42')->set('newPassword_confirmation', 'lantern-mango-river-42')
            ->call('savePassword')->assertHasNoErrors();

        $this->assertTrue(Hash::check('lantern-mango-river-42', $this->nur->fresh()->password));
        $this->assertFalse(DB::table('sessions')->where('id', 'the-old-laptop')->exists(), 'signed out elsewhere');
        Notification::assertSentTo($this->nur, SecurityNotice::class, fn ($n) => $n->kind === 'password');
    }

    public function test_changing_your_sign_in_email_needs_your_password(): void
    {
        $this->actingAs($this->nur);

        Livewire::test(Account::class)->set('email', 'Someone@Else.example')->call('saveProfile')->assertHasErrors('emailPassword');
        $this->assertSame('nur.aisyah@example.com', $this->nur->fresh()->email);

        Livewire::test(Account::class)->set('email', 'Nur@New.example')->set('emailPassword', 'old-kettle-orbit-9')->call('saveProfile')->assertHasNoErrors();
        $this->assertSame('nur@new.example', $this->nur->fresh()->email, 'kept in lower case');
        Notification::assertSentOnDemand(SecurityNotice::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'nur.aisyah@example.com');

        // Other details don't ask for it.
        Livewire::test(Account::class)->set('name', 'Nur A.')->call('saveProfile')->assertHasNoErrors();
    }

    public function test_a_reset_needs_a_strong_password_and_signs_out_everywhere(): void
    {
        $token = Password::createToken($this->nur);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'a-stolen-session', 'user_id' => $this->nur->id, 'ip_address' => '198.51.100.4', 'user_agent' => 'curl', 'payload' => '', 'last_activity' => now()->timestamp]);

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', 'nur.aisyah@example.com')->set('password', 'qwerty123')->set('password_confirmation', 'qwerty123')
            ->call('resetPassword')->assertHasErrors('password');

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', 'nur.aisyah@example.com')->set('password', 'harbour-violet-tide-31')->set('password_confirmation', 'harbour-violet-tide-31')
            ->call('resetPassword')->assertHasNoErrors();

        $this->assertFalse(DB::table('sessions')->where('user_id', $this->nur->id)->exists());
        Notification::assertSentTo($this->nur, SecurityNotice::class, fn ($n) => $n->kind === 'password_reset');
    }

    public function test_signing_in_is_not_fussy_about_capitals_in_the_email(): void
    {
        Livewire::test(Login::class)->set('email', 'Nur.Aisyah@Example.com')->set('password', 'old-kettle-orbit-9')->call('login');
        $this->assertAuthenticatedAs($this->nur);
    }

    public function test_uploads_keep_an_honest_extension_and_svgs_lose_their_scripts(): void
    {
        Storage::fake('public');
        $uploader = new AssetUploader('media');

        // A picture named like a web page is saved as the picture it is.
        $gif = UploadedFile::fake()->createWithContent('page.html', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
        $this->assertSame('gif', SafeUpload::extension($gif, ['jpg', 'png', 'gif']));
        $this->assertStringEndsWith('page.gif', $uploader->store($gif)->path());

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><circle r="4"/></svg>');
        $stored = Storage::disk('public')->get($uploader->store($svg)->path());
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
        $this->assertStringContainsString('<circle', $stored);
    }

    public function test_pages_carry_security_headers(): void
    {
        $this->actingAs($this->nur)->get(route('admin.contents'))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->assertStringContainsString('no-store', $this->get(route('admin.contents'))->headers->get('Cache-Control'));
        $this->assertStringContainsString('microphone=(self)', $this->get('/')->headers->get('Permissions-Policy'));
    }

    public function test_reader_notes_only_on_live_posts_and_link_heavy_ones_wait(): void
    {
        $draft = Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Secret draft'])->save();
        Livewire::test(\App\Livewire\Site\Comments::class, ['entryId' => $draft->id()])
            ->set('name', 'Reader')->set('body', 'Hello there')->call('post')->assertForbidden();

        $live = Booklet::entries()->make(['collection' => 'posts', 'published' => true, 'date' => now()->subDay()->toDateTimeString()], ['title' => 'Out now'])->save();
        Livewire::test(\App\Livewire\Site\Comments::class, ['entryId' => $live->id()])
            ->set('name', 'Visit https://spam.example')->set('body', 'Hi')->call('post')->assertHasErrors('name');

        Livewire::test(\App\Livewire\Site\Comments::class, ['entryId' => $live->id()])
            ->set('name', 'Linky')->set('body', 'https://a.example https://b.example https://c.example cheap!')->call('post')
            ->assertSet('posted', 'waiting');
    }
}
