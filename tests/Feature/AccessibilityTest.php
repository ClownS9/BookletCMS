<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The structural pieces of the accessibility pass, so they don't slip back.
 * (The full check runs axe-core in a browser over every page; see the pass's notes.)
 */
class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
    }

    public function test_the_admin_has_a_main_area_and_a_way_to_skip_to_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        foreach (['/admin', '/admin/settings', route('admin.entries.create', 'posts', false)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('<a href="#main" class="skip">', $html, $url);
            $this->assertMatchesRegularExpression('/<main class="book__pages" id="main"/', $html, $url);
            $this->assertMatchesRegularExpression('/<h1[\s>]/', $html, $url.' has a page heading');
        }

        $this->assertStringNotContainsString('role="grid"', $this->get('/admin/calendar')->getContent(), 'the calendar is a group of day buttons, not a data grid');
    }

    public function test_every_public_page_has_one_page_heading(): void
    {
        User::factory()->create(['role' => 'admin']);

        foreach (['/', '/blog', '/blog/hello-from-booklet', '/about', '/contact', '/search?q=hello'] as $url) {
            $this->assertSame(1, preg_match_all('/<h1[\s>]/', $this->get($url)->assertOk()->getContent()), $url);
        }
    }
}
