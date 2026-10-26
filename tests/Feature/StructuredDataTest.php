<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Inserts\InsertManager;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structured data (schema.org JSON-LD) on public pages.
 */
class StructuredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        User::factory()->create(['role' => 'admin']); // set up, so pages don't send people to setup
        Booklet::globals('site')->set('site_name', 'Kopi & Kuih')->set('tagline', 'Notes from a small kitchen')->save();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
    }

    /** @return array<int, array> the page's @graph */
    protected function graph(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m), 'one JSON-LD block');
        $data = json_decode($m[1][0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $data['@context']);

        return $data['@graph'];
    }

    protected function node(array $graph, string $type): array
    {
        return collect($graph)->firstWhere('@type', $type) ?? $this->fail("No {$type} in the graph: ".implode(', ', array_column($graph, '@type')));
    }

    public function test_a_post_is_an_article_with_its_author_dates_and_tags(): void
    {
        $author = User::factory()->create(['name' => 'Nur Aisyah', 'byline' => 'Nur', 'bio' => 'Bakes on weekends.']);
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $post->set('author', $author->id)->set('tags', ['baking'])->set('excerpt', 'A first hello.')->save();
        Booklet::terms()->make(['taxonomy' => 'tags', 'slug' => 'baking'], ['title' => 'Baking'])->save();

        $graph = $this->graph('/blog/hello-from-booklet');

        $site = $this->node($graph, 'WebSite');
        $this->assertSame('Kopi & Kuih', $site['name']);
        $this->assertStringEndsWith('/search?q={search_term_string}', $site['potentialAction']['target']['urlTemplate']);
        $this->assertSame('Kopi & Kuih', $this->node($graph, 'Organization')['name']);

        $article = $this->node($graph, 'BlogPosting');
        $this->assertSame('Hello from Booklet', $article['headline']);
        $this->assertSame('A first hello.', $article['description']);
        $this->assertSame('Nur', $article['author']['name']);
        $this->assertSame('Bakes on weekends.', $article['author']['description']);
        $this->assertSame('Baking', $article['keywords']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $article['datePublished']);
        $this->assertStringStartsWith('http', $article['url']);
        $this->assertSame(['@id' => $site['publisher']['@id']], $article['publisher']);

        $crumbs = $this->node($graph, 'BreadcrumbList')['itemListElement'];
        $this->assertSame(['Kopi & Kuih', 'Blog', 'Hello from Booklet'], array_column($crumbs, 'name'));
        $this->assertSame([1, 2, 3], array_column($crumbs, 'position'));
    }

    public function test_pages_say_what_kind_they_are_and_lists_list_their_posts(): void
    {
        $about = $this->node($this->graph('/about'), 'AboutPage');
        $this->assertSame('About', $about['name']);
        $this->node($this->graph('/contact'), 'ContactPage');

        $blog = $this->node($this->graph('/blog'), 'Blog');
        $this->assertContains('Hello from Booklet', array_column($blog['blogPost'], 'headline'));

        // Search results and email links are kept out of search engines.
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $this->get('/search?q=hello')->getContent());
        $this->assertStringNotContainsString('name="robots"', $this->get('/blog/hello-from-booklet')->getContent());
    }

    public function test_robots_txt_points_to_the_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Disallow: /admin')
            ->assertSee('Sitemap: '.url('/sitemap.xml'));

        Booklet::globals('site')->set('site_url', 'https://kopi.example')->save();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        $this->get('/robots.txt')->assertSee('Sitemap: https://kopi.example/sitemap.xml');
    }

    public function test_nothing_in_a_title_can_break_out_of_the_script(): void
    {
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $post->set('title', 'Cake </script><script>alert(1)</script> & more')->save();

        $html = $this->get('/blog/hello-from-booklet')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertSame('Cake </script><script>alert(1)</script> & more', $this->node($this->graph('/blog/hello-from-booklet'), 'BlogPosting')['headline']);
    }

    public function test_events_are_events_with_a_date_and_place(): void
    {
        config(['booklet.inserts_path' => base_path('tests/Fixtures/inserts')]);
        $this->app->forgetInstance(InsertManager::class);
        $manager = app(InsertManager::class);
        $manager->enable('events');
        $manager->reboot();
        $manager->find('events')->records('events')->create(['title' => 'Bake sale', 'starts' => now()->addDays(3)->format('Y-m-d').' 10:00', 'all_day' => false, 'venue' => 'Tamu Kianggeh', 'address' => 'Jalan Sultan Omar Ali']);
        $manager->find('events')->records('events')->create(['title' => 'Market day', 'starts' => now()->addDays(5)->format('Y-m-d').' 00:00', 'all_day' => true]);

        $events = collect($this->graph('/events'))->where('@type', 'Event')->keyBy('name');
        $this->assertSame('Tamu Kianggeh', $events['Bake sale']['location']['name']);
        $this->assertSame('Jalan Sultan Omar Ali', $events['Bake sale']['location']['address']);
        $this->assertStringContainsString('T10:00:00', $events['Bake sale']['startDate']);
        $this->assertSame(now()->addDays(5)->format('Y-m-d'), $events['Market day']['startDate']); // all day: just the date
        $this->assertArrayNotHasKey('location', $events['Market day']);
    }
}
