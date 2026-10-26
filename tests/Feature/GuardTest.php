<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Inserts\InsertManager;
use App\Livewire\Admin\InsertPage;
use App\Livewire\Admin\Inserts;
use App\Livewire\Auth\Login;
use App\Livewire\Site\Comments;
use App\Models\Comment;
use App\Models\InsertRecord;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Guard insert, and the hook in Booklet's forms it uses: a "prove
 * you're human" check, words and addresses to turn away, a log, and
 * sign-in protection that can't lock anyone out.
 */
class GuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $entryId;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        Notification::fake();
        config(['booklet.inserts_path' => base_path('tests/Fixtures/inserts')]);
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'nur@example.com']);
        $this->entryId = (string) Booklet::entries()->findBySlug('posts', 'hello-from-booklet')->id();
        $this->manager()->forget();
        $this->manager()->enable('guard');
        $this->manager()->reboot(); // its pages and views, as after switching it on
    }

    protected function manager(): InsertManager
    {
        return app(InsertManager::class);
    }

    protected function settings(array $values): void
    {
        $insert = $this->manager()->find('guard');
        $this->manager()->saveSettings('guard', array_merge($insert->settings(), $values));
        $this->manager()->forget();
    }

    protected function turnstile(array $more = []): void
    {
        $this->settings($more + ['provider' => 'turnstile', 'site_key' => '1x00000000000000000000AA', 'secret_key' => '1x0000000000000000000000000000000AA']);
    }

    protected function comment(string $token = '', string $body = 'Lovely layers.')
    {
        return Livewire::test(Comments::class, ['entryId' => $this->entryId])
            ->set('name', 'Hana')->set('body', $body)->set('guardToken', $token)->call('post');
    }

    public function test_secret_keys_are_kept_encrypted_never_sent_and_left_out_of_backups(): void
    {
        $this->turnstile();
        $stored = (string) DB::table('inserts')->where('key', 'guard')->value('settings');
        $this->assertStringNotContainsString('1x0000000000000000000000000000000AA', $stored);
        $this->assertSame('1x0000000000000000000000000000000AA', $this->manager()->find('guard')->setting('secret_key'));

        $shelf = Livewire::actingAs($this->admin)->test(Inserts::class)->call('pick', 'guard');
        $this->assertSame('', $shelf->get('values')['secret_key'], 'never sent to the browser');
        $shelf->assertSee('Saved · type to replace');

        // Saving with the box empty keeps the key.
        $shelf->set('values.site_key', 'changed-site-key')->call('saveSettings')->assertHasNoErrors();
        $this->manager()->forget();
        $this->assertSame('1x0000000000000000000000000000000AA', $this->manager()->find('guard')->setting('secret_key'));

        $backup = \App\Support\Backups::create('manual');
        $zip = new \ZipArchive;
        $zip->open(\App\Support\Backups::path($backup['name']));
        $inserts = (string) $zip->getFromName('tables/inserts.json');
        $zip->close();
        \App\Support\Backups::delete($backup['name']); // backups are real files: leave none behind for other tests
        $this->assertStringContainsString('guard', $inserts);
        $this->assertStringNotContainsString(\App\Inserts\Insert::SECRET, $inserts);
    }

    public function test_comments_need_the_check_to_pass(): void
    {
        $this->turnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => true])
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $before = Comment::count();
        $this->comment()->assertSee('class="guard-box"', false)->assertHasErrors('guard');
        $this->assertSame($before, Comment::count());

        $this->comment('a-good-answer')->assertHasNoErrors()->assertSet('guardToken', '')->assertDispatched('booklet-guard-reset');
        $this->assertSame($before + 1, Comment::count());

        $this->comment('a-bad-answer')->assertHasErrors('guard');
        $this->assertSame($before + 1, Comment::count());

        Http::assertSent(fn ($r) => $r['secret'] === '1x0000000000000000000000000000000AA' && $r['response'] === 'a-good-answer');
        $this->assertSame(['no-answer', 'failed'], InsertRecord::where('insert', 'guard')->where('kind', 'stops')->get()->map->field('why')->all());
    }

    /** What the guard says to something sent from this address. */
    protected function checkFrom(string $ip, string $form): ?string
    {
        request()->server->set('REMOTE_ADDR', $ip);
        $this->manager()->forget();

        return $this->manager()->guardCheck($form, ['message' => 'Hello there']);
    }

    public function test_words_and_addresses_without_any_check(): void
    {
        $this->settings(['blocked_words' => "cheap pills\ncasino", 'blocked_addresses' => "203.0.113.0/24\nnot an address"]);

        $before = Comment::query()->count();
        $this->comment('', 'Best CASINO in town')->assertHasErrors('guard');
        $this->comment('', 'Occasionally I bake')->assertHasNoErrors(); // a word, not part of one
        $this->assertSame($before + 1, Comment::query()->count());

        $this->assertSame('Sorry, this can’t be sent from your connection.', $this->checkFrom('203.0.113.9', 'contact'));
        $this->assertNull($this->checkFrom('198.51.100.4', 'contact'));
        $this->assertNull($this->checkFrom('203.0.113.9', 'login'), 'never on sign-in: you can’t lock yourself out');

        request()->server->set('REMOTE_ADDR', '127.0.0.1');
        Livewire::test(Login::class)->set('email', 'nur@example.com')->set('password', 'password')->call('login')->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_sign_in_is_guarded_only_once_the_keys_have_worked(): void
    {
        $this->turnstile(['on_login' => true]);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        // Not tried yet: no box, and signing in works as before.
        Livewire::test(Login::class)->assertDontSee('guard-box')->set('email', 'nur@example.com')->set('password', 'password')->call('login')->assertHasNoErrors();
        auth()->logout();

        Livewire::actingAs($this->admin)->test(InsertPage::class, ['insert' => 'guard'])
            ->assertSee('Try the keys')->set('form.token', 'from-the-box')->call('act', 'test')->assertDispatched('stamp');
        auth()->logout();
        $this->manager()->forget();

        Livewire::test(Login::class)->assertSee('guard-box', false)
            ->set('email', 'nur@example.com')->set('password', 'password')->call('login')->assertHasErrors('guard');
        $this->assertGuest();
        Livewire::test(Login::class)->set('email', 'nur@example.com')->set('password', 'password')->set('guardToken', 'ok')->call('login')->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_a_wrong_key_never_locks_anyone_out(): void
    {
        $this->turnstile(['on_login' => true]);
        $state = $this->manager()->find('guard')->records('state');
        $state->create(['keys' => sha1('turnstile|1x00000000000000000000AA|1x0000000000000000000000000000000AA'), 'at' => now()->toIso8601String()], 'tried');
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']])]);

        Livewire::test(Login::class)->set('email', 'nur@example.com')->set('password', 'password')->set('guardToken', 'x')->call('login')->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_when_the_check_cant_be_reached(): void
    {
        $this->turnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::response('', 503)]);
        $before = Comment::count();
        $this->comment('an-answer')->assertHasNoErrors(); // let through, and noted
        $this->assertSame($before + 1, Comment::count());

        $this->settings(['unreachable' => 'stop']);
        $this->comment('an-answer')->assertHasErrors('guard');
        $this->assertSame($before + 1, Comment::count());
    }

    public function test_recaptcha_v3_scores(): void
    {
        $this->settings(['provider' => 'recaptcha3', 'site_key' => 'site', 'secret_key' => 'secret', 'min_score' => 0.5]);
        Http::fake(['www.google.com/recaptcha/*' => Http::sequence()
            ->push(['success' => true, 'score' => 0.2, 'action' => 'booklet_comment'])
            ->push(['success' => true, 'score' => 0.9, 'action' => 'booklet_comment'])]);

        $this->comment('t1')->assertHasErrors('guard')->assertSee('Protected by reCAPTCHA');
        $this->comment('t2')->assertHasNoErrors();
    }

    public function test_the_log_is_tidied_and_safe_mode_switches_it_off(): void
    {
        $this->turnstile();
        $this->comment()->assertHasErrors('guard');
        $old = InsertRecord::where('insert', 'guard')->where('kind', 'stops')->first();
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->comment()->assertHasErrors('guard');

        $daily = $this->manager()->find('guard')->manifest('every')['daily'];
        $daily($this->manager()->find('guard'));
        $this->assertSame(1, InsertRecord::where('insert', 'guard')->where('kind', 'stops')->count());

        config(['booklet.safe_mode' => true]);
        $this->manager()->forget();
        $this->comment()->assertHasNoErrors()->assertDontSee('guard-box');
    }
}
