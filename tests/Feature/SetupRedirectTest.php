<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A brand new book opens on First setup: the public site, the admin and
 * sign in all lead there until the first account exists.
 */
class SetupRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class); // starter content, but nobody yet
    }

    public function test_every_page_goes_to_setup_before_anyone_exists(): void
    {
        foreach (['/', '/blog/hello-from-booklet', '/about', '/search?q=hello', '/login', '/admin', '/feed.xml'] as $url) {
            $this->get($url)->assertRedirect(route('setup'));
        }

        $this->get('/setup')->assertOk();
    }

    public function test_setup_can_still_talk_to_the_server(): void
    {
        // Livewire's script and its updates (at "/livewire/…" or, in newer versions,
        // "/livewire-1a2b3c4d/…") must never be sent back to setup, or its form goes dead.
        foreach (['/livewire/update', '/livewire-1a2b3c4d/update'] as $url) {
            $this->assertNotSame(302, $this->postJson($url, [])->getStatusCode(), $url);
        }
        foreach (['/livewire/livewire.js', '/livewire/livewire.min.js', '/livewire-1a2b3c4d/livewire.min.js'] as $url) {
            $this->assertNotSame(302, $this->get($url)->getStatusCode(), $url);
        }
    }

    public function test_once_someone_exists_the_site_opens_and_setup_closes(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->get('/')->assertOk();
        $this->get('/setup')->assertRedirect(route('login'));
    }
}
