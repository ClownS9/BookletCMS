<?php

namespace Tests\Feature;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\PageBuild;
use App\Models\User;
use App\PageBuilder\PageBuilder;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A built page (and the blog page) is a draft, scheduled or published.
 * Scheduling one that's on the site already schedules its changes: readers
 * see what's there now until the time comes.
 */
class PageBuilderStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['booklet.timezone' => 'Asia/Brunei']); // the site's time (applied to each request, and to the scheduler)
        \App\Http\Middleware\ApplySiteSettings::applyTimezone();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->travelTo(Carbon::parse('2026-10-24 10:00', 'Asia/Brunei'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
        parent::tearDown();
    }

    /** A new page, not on the site, being built. */
    protected function made(): Entry
    {
        $entry = Booklet::entries()->make(['collection' => 'pages', 'published' => false], ['title' => 'Spring offer', 'content' => ''])->save();
        $this->actingAs($this->admin)->postJson(route('builder.start', $entry->id()))->assertOk();

        return $entry;
    }

    protected function act(Entry|string $target, string $action, mixed $payload = null): TestResponse
    {
        $target = $target instanceof Entry ? $target->id() : $target;

        return $this->actingAs($this->admin)->postJson(route('builder.act', $target), ['action' => $action, 'payload' => $payload, 'state' => ['tab' => 'page']]);
    }

    protected function title(Entry $entry, string $words): void
    {
        $this->act($entry, 'set', ['id' => PageBuild::forEntry($entry)->draft[0]['id'], 'field' => 'title', 'value' => $words]);
    }

    protected function reader(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);

        return $this->get($url);
    }

    public function test_a_page_is_scheduled_and_goes_live_on_time(): void
    {
        $page = $this->made();
        $drawer = $this->actingAs($this->admin)->getJson(route('builder.edit', $page->id()).'?tab=page')->assertOk()->json('drawer');
        $this->assertStringContainsString('data-form="status"', $drawer);
        $this->assertStringContainsString('value="2026-10-25T09:00"', $drawer, 'tomorrow at nine, to start from');

        // Times are checked.
        $this->act($page, 'status', ['status' => 'scheduled', 'at' => ''])->assertStatus(422)->assertJsonPath('errors.at', 'Choose a day and a time.');
        $this->act($page, 'status', ['status' => 'scheduled', 'at' => '2026-10-24T09:00'])->assertStatus(422)->assertJsonPath('errors.at', 'Choose a time that hasn’t been yet.');
        $this->assertStringContainsString('value="2020-01-01T09:00"', $this->act($page, 'status', ['status' => 'scheduled', 'at' => '2020-01-01T09:00'])->json('drawer'), 'shown again, to fix');

        // In the site's time (Brunei, here).
        $this->act($page, 'status', ['status' => 'scheduled', 'at' => '2026-11-01T09:00'])->assertOk()
            ->assertJsonPath('stamp', 'It goes live on Sun 1 Nov, 9:00am.')
            ->assertJsonPath('bar.status', 'scheduled')->assertJsonPath('bar.words', 'Scheduled for Sun 1 Nov, 9:00am')->assertJsonPath('bar.live', false);
        $this->assertSame('2026-11-01T01:00:00+00:00', PageBuild::forEntry($page)->scheduled_at->utc()->toIso8601String());
        $this->reader('/spring-offer')->assertNotFound();
        $this->assertSame('2026-11-01 01:00', app(PageBuilder::class)->nextScheduled()?->utc()->format('Y-m-d H:i'), 'pages kept for readers are made again then');

        // What goes live is the page as it was scheduled; later changes wait, unless added.
        $this->title($page, 'Later words');
        $this->assertStringContainsString('Include my latest changes', $this->act($page, 'tab', 'page')->json('drawer'));
        $this->title($page, 'Spring offer, scheduled');
        $this->act($page, 'reschedule')->assertJsonPath('stamp', 'Your latest changes are in it too. It still goes live on Sun 1 Nov, 9:00am.');
        $this->assertStringNotContainsString('Include my latest changes', $this->act($page, 'tab', 'page')->json('drawer'));
        $this->title($page, 'After that');

        // The time comes: Booklet's scheduler puts it on the site (the page itself, too).
        $this->travelTo(Carbon::parse('2026-11-01 09:01', 'Asia/Brunei'));
        $this->assertSame(1, app(PageBuilder::class)->settle());
        $this->reader('/spring-offer')->assertOk()->assertSee('Spring offer, scheduled')->assertDontSee('After that');
        $build = PageBuild::forEntry($page);
        $this->assertNull($build->scheduled_at);
        $this->assertTrue(Booklet::entries()->find($page->id())->published());
        $this->assertDatabaseHas('activity_log', ['action' => 'published (scheduled)', 'subject_key' => $page->id()]);
        $this->act($page, 'tab', 'page')->assertJsonPath('bar.status', 'changed')->assertJsonPath('bar.live', true);
    }

    public function test_changes_to_a_live_page_are_scheduled_and_go_live_on_the_next_visit(): void
    {
        $page = $this->made();
        $this->title($page, 'First words');
        $this->act($page, 'status', ['status' => 'published'])->assertJsonPath('bar.status', 'live');
        $this->title($page, 'New words');
        $this->act($page, 'status', ['status' => 'scheduled', 'at' => '2026-10-24T18:30'])
            ->assertJsonPath('stamp', 'Your changes go live on Sat 24 Oct, 6:30pm. Until then readers see what’s on the site now.')
            ->assertJsonPath('bar.words', 'Changes scheduled for Sat 24 Oct, 6:30pm')->assertJsonPath('bar.live', true);
        $this->reader('/spring-offer')->assertSee('First words')->assertDontSee('New words');

        $this->travelTo(Carbon::parse('2026-10-24 18:31', 'Asia/Brunei'));
        $this->reader('/spring-offer')->assertSee('New words')->assertDontSee('First words');
        $this->assertDatabaseHas('activity_log', ['action' => 'published changes to (scheduled)']);
    }

    public function test_draft_published_and_not_scheduling_after_all(): void
    {
        $page = $this->made();
        $this->act($page, 'status', ['status' => 'draft'])->assertJsonPath('stamp', 'It’s a draft: nobody else sees it.');
        $this->act($page, 'status', ['status' => 'scheduled', 'at' => '2026-10-30T12:00']);
        $this->act($page, 'unschedule')->assertJsonPath('stamp', 'Not scheduled any more.')->assertJsonPath('bar.status', 'draft');

        $this->act($page, 'status', ['status' => 'scheduled', 'at' => '2026-10-30T12:00']);
        $this->act($page, 'status', ['status' => 'published'])->assertJsonPath('bar.status', 'live');
        $this->assertNull(PageBuild::forEntry($page)->scheduled_at, 'publishing now ends the schedule');
        $this->reader('/spring-offer')->assertOk();

        // A draft again: off the site, kept here; a schedule goes with it.
        $this->act($page, 'status', ['status' => 'scheduled', 'at' => '2026-10-30T12:00']);
        $this->act($page, 'status', ['status' => 'draft'])->assertJsonPath('bar.status', 'draft');
        $this->assertNull(PageBuild::forEntry($page)->scheduled_at);
        $this->travelTo(Carbon::parse('2026-10-31 12:00', 'Asia/Brunei'));
        $this->reader('/spring-offer')->assertNotFound();
        $this->assertSame('Spring offer', PageBuild::forEntry($page)->draft[0]['fields']['title']);

        $this->act($page, 'status', ['status' => 'whatever'])->assertOk()->assertJsonPath('stamp', null);
    }

    public function test_the_blog_page_is_scheduled_too(): void
    {
        $this->actingAs($this->admin)->getJson(route('builder.edit', 'blog'))->assertOk();
        $heading = collect(PageBuild::blog()->draft)->firstWhere('type', 'heading')['id'];
        $this->act('blog', 'set', ['id' => $heading, 'field' => 'title', 'value' => 'Notes from the kitchen']);
        $drawer = $this->act('blog', 'status', ['status' => 'scheduled', 'at' => '2026-10-26T08:00'])
            ->assertJsonPath('stamp', 'The arrangement goes live on Mon 26 Oct, 8:00am.')->json('drawer');
        $this->assertStringContainsString('Readers see the blog as The cover has it.', $drawer);
        $this->reader('/blog')->assertDontSee('Notes from the kitchen');

        $this->travelTo(Carbon::parse('2026-10-26 08:05', 'Asia/Brunei'));
        $this->reader('/blog')->assertSee('Notes from the kitchen');
        $this->assertDatabaseHas('activity_log', ['action' => 'arranged the blog page (scheduled)']);

        // Draft: the blog follows The cover again.
        $this->act('blog', 'status', ['status' => 'draft'])->assertJsonPath('stamp', 'The blog follows The cover’s layout again. (Your arrangement is kept as a draft.)');
        $this->reader('/blog')->assertDontSee('Notes from the kitchen');
    }
}
