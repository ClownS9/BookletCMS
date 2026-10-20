<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use App\Models\User;
use App\Support\PageCache;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faster public pages: kept, served again, and made fresh when the book changes.
 */
class PageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function visit(string $url)
    {
        // Each visit is a fresh request in real life: no copy of the site carried over.
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);

        return $this->get($url)->assertOk();
    }

    public function test_a_page_is_kept_and_served_again(): void
    {
        $first = $this->visit('/blog/hello-from-booklet')->assertHeader('X-Booklet-Cache', 'miss');
        $again = $this->visit('/blog/hello-from-booklet')->assertHeader('X-Booklet-Cache', 'hit');

        $this->assertStringNotContainsString('__BOOKLET_FORM_TOKEN__', $again->getContent());
        $this->assertStringContainsString(csrf_token(), $again->getContent(), 'each reader gets their own form token back');
        $this->assertSame(
            str_replace(csrf_token(), '', $first->getContent()),
            str_replace(csrf_token(), '', $again->getContent()),
        );
    }

    public function test_only_plain_visits_by_readers_are_kept(): void
    {
        $this->visit('/search?q=hello')->assertHeaderMissing('X-Booklet-Cache');
        $this->visit('/blog?utm_source=x')->assertHeaderMissing('X-Booklet-Cache');
        $this->visit('/blog?page=1')->assertHeader('X-Booklet-Cache', 'miss');
        $this->get('/no-such-page')->assertNotFound()->assertHeaderMissing('X-Booklet-Cache');

        $this->actingAs($this->admin)->get('/blog/hello-from-booklet')->assertOk()->assertHeaderMissing('X-Booklet-Cache');
    }

    public function test_changes_make_pages_fresh(): void
    {
        $this->visit('/blog/hello-from-booklet');
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $post->set('title', 'Hello again')->save();

        $this->visit('/blog/hello-from-booklet')->assertHeader('X-Booklet-Cache', 'miss')->assertSee('Hello again');

        // An approved comment shows at once.
        $this->visit('/blog/hello-from-booklet')->assertHeader('X-Booklet-Cache', 'hit');
        Comment::create(['entry_id' => $post->id(), 'author_name' => 'Hana', 'body' => 'Lovely cake', 'status' => 'approved']);
        $this->visit('/blog/hello-from-booklet')->assertHeader('X-Booklet-Cache', 'miss')->assertSee('Lovely cake');
    }

    public function test_a_scheduled_post_appears_when_its_time_comes(): void
    {
        Booklet::entries()->make(['collection' => 'posts', 'published' => true, 'date' => now()->addMinutes(10)->toDateTimeString()], ['title' => 'Coming soon'])->save();

        $this->visit('/blog')->assertDontSee('Coming soon');
        $this->visit('/blog')->assertHeader('X-Booklet-Cache', 'hit');

        $this->travel(11)->minutes();
        $this->visit('/blog')->assertHeader('X-Booklet-Cache', 'miss')->assertSee('Coming soon');
    }

    public function test_it_can_be_switched_off_and_cleared(): void
    {
        $this->visit('/');
        PageCache::flush();
        $this->visit('/')->assertHeader('X-Booklet-Cache', 'miss');

        Booklet::globals('site')->merge(['page_cache' => false])->save();
        $this->visit('/')->assertHeaderMissing('X-Booklet-Cache');
    }

    public function test_new_templates_mean_fresh_pages(): void
    {
        $this->visit('/about');
        $this->visit('/about')->assertHeader('X-Booklet-Cache', 'hit');

        // An update to Booklet (or a theme edit) changes the site's templates.
        $file = resource_path('views/site/page.blade.php');
        $was = filemtime($file);
        touch($file, $was + 60);
        try {
            $this->visit('/about')->assertHeader('X-Booklet-Cache', 'miss');
        } finally {
            touch($file, $was);
        }
    }
}
