<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\StacyOrb;
use App\Livewire\Auth\Login;
use App\Models\AiProvider;
use App\Models\Comment;
use App\Models\User;
use App\Stacy\Greeting;
use App\Stacy\StacyConfig;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stacy says hello on sign-in, on first setup and after time away, and
 * stays quiet when switched off for the book or for the person.
 */
class StacyGreetingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'is_default' => true, 'api_key' => 'sk-or-test', 'default_model' => 'deepseek/deepseek-chat']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah', 'password' => bcrypt('secret-pass')]);
    }

    public function test_signing_in_leaves_a_hello_for_the_first_page(): void
    {
        Livewire::test(Login::class)
            ->set('email', $this->admin->email)
            ->set('password', 'secret-pass')
            ->call('login');

        $this->assertSame('login', session('stacy.greet'));

        $orb = Livewire::actingAs($this->admin)->test(StacyOrb::class);
        $greeting = $orb->get('greeting');

        $this->assertNotNull($greeting);
        $this->assertSame('login', $greeting['kind']);
        $this->assertStringContainsString('Nur', $greeting['title']);
        $this->assertNull(session('stacy.greet'), 'shown once, then gone');
    }

    public function test_it_mentions_what_is_waiting_with_a_button(): void
    {
        $entry = Booklet::entries()->all()->first();
        Comment::create(['entry_id' => $entry->id(), 'author_name' => 'A reader', 'author_email' => 'r@example.com', 'body' => 'Lovely!', 'status' => 'waiting']);

        $greeting = Greeting::for($this->admin, 'login');

        $this->assertStringContainsString('comment', implode(' ', $greeting['lines']));
        $this->assertSame(route('admin.comments'), $greeting['actions'][0]['href']);
    }

    public function test_coming_back_after_hours_away_says_welcome_back(): void
    {
        $this->admin->forceFill(['last_seen_at' => now()->subHours(9)])->save();

        $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()->assertSee('orb__greet', false);

        // A short break doesn't count.
        $this->admin->forceFill(['last_seen_at' => now()->subMinutes(30)])->save();
        $this->actingAs($this->admin)->get(route('admin.contents'))->assertDontSee('orb__greet', false);
    }

    public function test_a_tab_left_open_is_welcomed_back_too(): void
    {
        Livewire::actingAs($this->admin)->test(StacyOrb::class)
            ->assertSet('greeting', null)
            ->call('welcomeBack', 20)->assertSet('greeting', null)
            ->call('welcomeBack', 6 * 60)
            ->assertSet('greeting.kind', 'back');
    }

    public function test_it_can_be_switched_off_for_the_book_or_for_one_person(): void
    {
        StacyConfig::save(['greet' => false]);
        $this->assertFalse(Greeting::wanted($this->admin));

        StacyConfig::save(['greet' => true]);
        $this->assertTrue(Greeting::wanted($this->admin));

        Livewire::actingAs($this->admin)->test(StacyOrb::class)
            ->call('welcomeBack', 6 * 60)
            ->call('stopGreetings')
            ->assertSet('greeting', null);

        $this->assertFalse((bool) $this->admin->fresh()->preferences['stacy_greet']);
        $this->assertFalse(Greeting::wanted($this->admin->fresh()));
    }

    public function test_the_browser_cannot_write_its_own_greeting(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin)->test(StacyOrb::class)
            ->set('greeting', ['kind' => 'login', 'title' => 'x', 'lines' => [], 'actions' => [['label' => 'Evil', 'href' => 'https://evil.test']], 'say' => '', 'aloud' => false]);
    }

    public function test_the_chime_is_on_by_default_and_can_be_switched_off(): void
    {
        $this->assertTrue(Greeting::for($this->admin, 'login')['sound']);

        StacyConfig::save(['greet_sound' => false]);
        $this->assertFalse(Greeting::for($this->admin, 'login')['sound']);
    }

    public function test_she_says_the_greeting_out_loud_unless_switched_off(): void
    {
        $greeting = Greeting::for($this->admin, 'login');
        $this->assertTrue($greeting['aloud']);
        $this->assertStringStartsWith($greeting['title'], $greeting['say']);

        StacyConfig::save(['greet_aloud' => false]);
        $this->assertFalse(Greeting::for($this->admin, 'login')['aloud']);
    }

    public function test_hello_follows_the_clock(): void
    {
        $this->assertSame('Good morning', Greeting::hello(now()->setTime(9, 0)));
        $this->assertSame('Good afternoon', Greeting::hello(now()->setTime(14, 0)));
        $this->assertSame('Good evening', Greeting::hello(now()->setTime(19, 30)));
    }
}
