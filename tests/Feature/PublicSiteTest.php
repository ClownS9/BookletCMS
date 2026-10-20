<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public site, against the starter book (database driver; see phpunit.xml).
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);

        // A book that has been through First setup (otherwise every page goes to /setup).
        \App\Models\User::factory()->create(['role' => 'admin']);
    }

    public function test_the_homepage_lists_published_posts(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Hello from Booklet')
            ->assertSee('made with booklet');
    }

    public function test_a_post_is_found_by_its_address(): void
    {
        $this->get('/blog/hello-from-booklet')
            ->assertOk()
            ->assertSee('Hello from Booklet')
            ->assertSee('Notes in the margin');
    }

    public function test_pages_and_term_archives_resolve(): void
    {
        $this->get('/about')->assertOk()->assertSee('About');
        $this->get('/blog/category/news')->assertOk()->assertSee('Hello from Booklet');
        $this->get('/blog')->assertOk()->assertSee('Hello from Booklet');
    }

    public function test_drafts_stay_hidden(): void
    {
        Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Secret draft'])->save();

        $this->get('/')->assertDontSee('Secret draft');
        $this->get('/blog/secret-draft')->assertNotFound();
        $this->get('/search?q=secret')->assertDontSee('Secret draft');
    }

    public function test_search_and_the_torn_page(): void
    {
        $this->get('/search?q=hello')->assertOk()->assertSee('1 result');
        $this->get('/no-such-page')->assertNotFound()->assertSee('This page has been torn out');
    }

    public function test_the_feed_lists_published_posts(): void
    {
        $this->get('/feed.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->assertSee('<title>Hello from Booklet</title>', false)
            ->assertDontSee('Secret draft');
    }

    public function test_the_sitemap_has_posts_pages_and_terms(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/blog/hello-from-booklet'), false)
            ->assertSee(url('/about'), false)
            ->assertSee(url('/blog/category/news'), false);
    }

    public function test_pages_offer_dark_reading_and_the_feed(): void
    {
        $this->get('/')
            ->assertSee('data-theme-toggle', false)
            ->assertSee(route('site.feed'), false);
    }
}
