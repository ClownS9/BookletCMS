<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\PageBuild;
use App\Models\User;
use App\Support\Look;
use App\Themes\ThemeManager;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The blog page is arranged the same way: the blog's own sections (the
 * featured story, the categories, the posts and their sidebar) among any of
 * the others, on the page itself. Until someone publishes an arrangement,
 * readers see Booklet's own blog, as The cover draws it.
 */
class PageBuilderBlogTest extends TestCase
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

    protected function tearDown(): void
    {
        File::deleteDirectory(ThemeManager::path());
        parent::tearDown();
    }

    protected function act(string $action, mixed $payload = null, array $state = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson(route('builder.act', 'blog'), ['action' => $action, 'payload' => $payload, 'state' => $state]);
    }

    protected function blog(): PageBuild
    {
        return PageBuild::blog() ?? $this->fail('no blog page');
    }

    protected function id(string $type): string
    {
        return collect($this->blog()->draft)->firstWhere('type', $type)['id'];
    }

    protected function reader(string $url = '/blog'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);

        return $this->get($url)->assertOk();
    }

    public function test_until_arranged_readers_see_booklets_own_blog(): void
    {
        $this->reader()->assertSee('class="feature"', false)->assertSee('look-home--feature', false)->assertSee('The blog')
            ->assertDontSee('pb-editor', false)->assertDontSee('pb-blog', false);
        $this->assertSame(0, PageBuild::count(), 'nothing made just by looking');

        // People who can arrange it get the button.
        $this->actingAs($this->admin)->get('/blog')->assertSee('id="pb-editor"', false)->assertSee('Arrange this page')
            ->assertSee(route('builder.edit', 'blog'), false);
        $this->actingAs(User::factory()->create(['role' => 'writer']))->get('/blog')->assertDontSee('pb-editor', false);
    }

    public function test_arranging_starts_from_the_cover_and_waits_for_publish(): void
    {
        Look::save(Look::clean(array_merge(Look::current(), ['home' => 'list'])));
        $r = $this->actingAs($this->admin)->getJson(route('builder.edit', 'blog'))->assertOk()
            ->assertJsonPath('bar.blog', true)->assertJsonPath('bar.words', 'Following The cover');
        $this->assertSame(['heading', 'blog-categories', 'blog-posts', 'subscribe'], array_column($this->blog()->draft, 'type'));
        $this->assertSame('list', $this->blog()->draft[2]['fields']['style']);
        $this->assertStringContainsString('data-pb-required', $r->json('page'));

        // Its own sections, and the others; the posts can't go, or be added twice.
        $this->assertStringContainsString('Featured story', $this->act('adding', true)->json('drawer'));
        $this->act('add', 'blog-featured', ['section' => $this->id('heading')]);
        $this->act('add', 'blog-featured')->assertJsonPath('stamp', 'There’s one on the page already.');
        $this->assertStringNotContainsString('Featured story', $this->act('adding', true)->json('drawer'));
        $this->act('copy', $this->id('blog-categories'))->assertJsonPath('stamp', 'It shows once on the page.');
        $this->act('add', 'hero', ['section' => $this->id('blog-posts')]);
        $this->act('add', 'blog-posts');
        $this->assertSame(1, collect($this->blog()->draft)->where('type', 'blog-posts')->count());
        $this->act('remove', $this->id('blog-posts'))->assertJsonPath('stamp', 'The posts are what the blog page is for: they can move, but not go.');
        $this->act('copy', $this->id('blog-posts'))->assertJsonPath('stamp', 'The posts show once on the blog page.');

        $this->act('set', ['id' => $this->id('heading'), 'field' => 'title', 'value' => 'Notes from the kitchen']);
        $this->act('set', ['id' => $this->id('blog-posts'), 'field' => 'style', 'value' => 'wide']);
        $this->act('set', ['id' => $this->id('blog-posts'), 'field' => 'sidebar', 'value' => 'left']);
        $this->act('set', ['id' => $this->id('blog-posts'), 'field' => 'recent', 'value' => false]);
        $this->reader()->assertDontSee('Notes from the kitchen');

        $this->act('publish')->assertJsonPath('stamp', 'Published. Readers see the blog arranged like this now.')->assertJsonPath('bar.status', 'live');
        $html = $this->reader()
            ->assertSee('<h1 class="pb-title pb-heading__title">Notes from the kitchen</h1>', false)
            ->assertSee('blog-posts--wide', false)->assertSee('with-aside--left', false)
            ->assertSee('class="feature"', false)->assertSee('pb-hero', false)->assertDontSee('Recently')
            ->assertDontSee('data-pb-field', false)->assertDontSee('pb-editor', false)->getContent();
        $this->assertLessThan(strpos($html, 'pb--blog-posts'), strpos($html, 'pb--blog-featured'));

        $this->reader('/blog?page=2')->assertDontSee('class="feature"', false);
        $this->reader('/blog?category=nothing-here')->assertSee('Nothing in this chapter yet.');

        // It isn't one of the pages: no home page, no details, no stopping.
        foreach ([['home', true], ['details', ['name' => 'x', 'address' => 'y']], ['stop', null]] as [$action, $payload]) {
            $this->act($action, $payload)->assertOk()->assertJsonPath('stamp', null);
        }
        $this->assertNotNull(PageBuild::blog());
    }

    public function test_it_can_follow_the_cover_again(): void
    {
        $this->actingAs($this->admin)->getJson(route('builder.edit', 'blog'));
        $this->act('set', ['id' => $this->id('heading'), 'field' => 'title', 'value' => 'Arranged']);
        $this->act('publish');
        $this->reader()->assertSee('Arranged');

        $this->act('unpublish')->assertJsonPath('stamp', 'The blog follows The cover’s layout again. (Your arrangement is kept as a draft.)');
        $this->reader()->assertDontSee('Arranged')->assertSee('look-home--feature', false);
        $this->assertSame('Arranged', $this->blog()->draft[0]['fields']['title'], 'kept as a draft');
    }

    public function test_the_phone_view_shows_the_draft_only_to_arrangers(): void
    {
        $this->actingAs($this->admin)->getJson(route('builder.edit', 'blog'));
        $this->act('set', ['id' => $this->id('heading'), 'field' => 'title', 'value' => 'Draft heading']);
        $this->actingAs($this->admin)->get(route('builder.preview', 'blog'))->assertRedirect(app(\App\Booklet\Site::class)->blogUrl().'?preview=1');
        $this->actingAs($this->admin)->get('/blog?preview=1')->assertOk()->assertSee('Draft heading')->assertDontSee('id="pb-editor"', false);
        $this->reader('/blog?preview=1')->assertDontSee('Draft heading');
    }

    public function test_a_theme_that_draws_the_blog_itself_isnt_arranged_here(): void
    {
        config(['booklet.themes_path' => storage_path('framework/testing/themes')]);
        File::ensureDirectoryExists(ThemeManager::path().'/ownblog/views/site');
        File::put(ThemeManager::path().'/ownblog/theme.json', json_encode(['key' => 'ownblog', 'name' => 'Own blog', 'version' => '1.0']));
        File::put(ThemeManager::path().'/ownblog/views/site/blog.blade.php', '<p>A blog of its own</p>');
        ThemeManager::forget();
        Look::save(Look::clean(['theme' => 'ownblog']));

        $this->actingAs($this->admin)->getJson(route('builder.edit', 'blog'))->assertNotFound();
    }
}
