<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use App\Models\User;
use App\Support\BookStats;
use App\Support\VisitStats;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finding your way (stickers that open, the thumb index, the phone dock and
 * its sheets) and the numbers on the Contents spread and Visits.
 */
class MenusAndNumbersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // Counting when and on what ---------------------------------------------

    public function test_each_visit_is_counted_by_hour_and_kind_of_device(): void
    {
        // Tomorrow evening: after the starter post went out, whenever the tests run.
        Carbon::setTestNow(Carbon::tomorrow(config('app.timezone'))->setTime(20, 15));

        $this->get('/blog/hello-from-booklet', ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148'])->assertOk();
        $this->get('/blog/hello-from-booklet', ['User-Agent' => 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)'])->assertOk();
        $this->get('/blog/hello-from-booklet', ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Safari/605.1.15'])->assertOk();

        $this->assertSame(3, (int) DB::table('visit_hours')->where('hour', 20)->value('views'));
        $this->assertSame(['computer' => 1, 'phone' => 1, 'tablet' => 1], DB::table('device_stats')->orderBy('device')->pluck('views', 'device')->map(fn ($v) => (int) $v)->all());
    }

    public function test_readers_who_ask_not_to_be_tracked_are_not_counted_by_hour_or_device(): void
    {
        $this->get('/blog/hello-from-booklet', ['DNT' => '1'])->assertOk();

        $this->assertSame(0, DB::table('visit_hours')->count());
        $this->assertSame(0, DB::table('device_stats')->count());
    }

    public function test_devices_are_told_apart_from_the_browser_description(): void
    {
        $this->assertSame('phone', VisitStats::device('Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari/537.36'));
        $this->assertSame('tablet', VisitStats::device('Mozilla/5.0 (Linux; Android 14; SM-X710) Safari/537.36'));
        $this->assertSame('tablet', VisitStats::device('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)'));
        $this->assertSame('computer', VisitStats::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0'));
        $this->assertSame('computer', VisitStats::device(''));
    }

    public function test_hours_are_added_up_by_weekday_in_two_hour_blocks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00')); // a Friday
        DB::table('visit_hours')->insert([
            ['day' => '2026-10-30', 'hour' => 21, 'views' => 9],
            ['day' => '2026-10-30', 'hour' => 20, 'views' => 3],
            ['day' => '2026-10-26', 'hour' => 7, 'views' => 4], // Monday
            ['day' => '2026-09-01', 'hour' => 7, 'views' => 99], // too long ago
        ]);

        $hours = VisitStats::hours(7);

        $this->assertSame(21, $hours['busiest']);
        $this->assertSame(12, $hours['week'][4][10]); // Friday, 20:00–22:00
        $this->assertSame(4, $hours['week'][0][3]); // Monday, 06:00–08:00
        $this->assertSame(16, array_sum($hours['hours']));
    }

    // The numbers on Contents -------------------------------------------------

    public function test_contents_shows_this_week_against_last_and_the_month_in_ink(): void
    {
        foreach (range(0, 13) as $ago) {
            DB::table('site_visits')->insert(['day' => now()->subDays($ago)->toDateString(), 'views' => $ago < 7 ? 30 : 20, 'visitors' => 10]);
        }
        DB::table('referrer_stats')->insert(['day' => now()->toDateString(), 'source' => 'Google', 'views' => 40]);
        DB::table('device_stats')->insert(['day' => now()->toDateString(), 'device' => 'phone', 'views' => 3]);
        DB::table('visit_hours')->insert(['day' => now()->toDateString(), 'hour' => 20, 'views' => 5]);

        $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()
            ->assertSee('At a glance · this week')
            ->assertSee('▲ 50%') // 210 visits against 140
            ->assertSee('Visits · last 30 days')
            ->assertSee('ink-chart__line', false)
            ->assertSee('Where they came from')
            ->assertSee('Google')
            ->assertSee('100% on phones.')
            ->assertSee('Published · these 4 weeks');
    }

    public function test_a_book_nobody_has_visited_says_so_instead_of_drawing_empty_charts(): void
    {
        $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()
            ->assertSee('No visits yet. Once people read the site, the month draws itself here.')
            ->assertDontSee('ink-chart__line', false);
    }

    public function test_waiting_for_you_counts_what_needs_a_hand(): void
    {
        $entry = Booklet::entries()->all()->first(fn ($e) => $e->published());
        $before = Comment::where('status', 'waiting')->count(); // the starter book has one of its own
        Comment::create(['entry_id' => $entry->id(), 'author_name' => 'A reader', 'body' => 'Lovely!', 'status' => 'waiting']);
        Comment::create(['entry_id' => $entry->id(), 'author_name' => 'Another', 'body' => 'Yes!', 'status' => 'waiting']);

        $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()
            ->assertSee('Waiting for you')
            ->assertSee('comments to read')
            ->assertSee('<span class="dock__badge">'.($before + 2).'</span>', false);
    }

    public function test_chart_tops_are_round_numbers(): void
    {
        $this->assertSame(4, BookStats::niceCeiling(3)); // small books still get a little room
        $this->assertSame(30, BookStats::niceCeiling(27));
        $this->assertSame(300, BookStats::niceCeiling(258));
        $this->assertSame(1000, BookStats::niceCeiling(1000));
    }

    public function test_where_readers_came_from_always_adds_up_to_the_whole(): void
    {
        foreach (['Google' => 33, 'Direct' => 33, 'Instagram' => 33, 'Facebook' => 1, 'bing' => 1, 'x.com' => 1, 'example.com' => 1] as $source => $views) {
            DB::table('referrer_stats')->insert(['day' => now()->toDateString(), 'source' => $source, 'views' => $views]);
        }

        $sources = BookStats::sources();

        $this->assertSame(100, array_sum(array_column($sources, 'share')));
        $this->assertContains('Typed it in', array_column($sources, 'name')); // "Direct", in plain words
        $this->assertCount(6, $sources); // the top five, then the rest together
        $this->assertSame('Elsewhere', end($sources)['name']);
    }

    // Finding your way --------------------------------------------------------

    public function test_chapter_stickers_open_into_their_pages_with_counts(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk();

        $page->assertSee('id="flag-note-posts"', false)
            ->assertSee('All posts')->assertSee('New post')->assertSee(route('admin.entries', ['posts', 'status' => 'draft']), false)
            ->assertSee('id="flag-note-pages"', false)->assertSee(route('admin.menus'), false)
            ->assertSee('id="flag-note-people"', false)->assertSee('Invite someone')
            ->assertSee('id="flag-note-gear"', false)->assertSee('The back of the book');
    }

    public function test_a_writer_only_sees_what_they_may_open(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);

        $page = $this->actingAs($writer)->get(route('admin.contents'))->assertOk();

        $page->assertSee('id="flag-note-posts"', false)
            ->assertDontSee('id="flag-note-people"', false) // readers and subscribers are for editors
            ->assertDontSee('Invite someone')
            ->assertDontSee(route('admin.inbox'), false)
            ->assertDontSee(route('admin.forms'), false)
            ->assertDontSee(route('admin.backups'), false);
    }

    public function test_the_thumb_index_holds_the_tools_and_marks_the_one_open(): void
    {
        $this->actingAs($this->admin)->get(route('admin.visits'))->assertOk()
            ->assertSee('class="thumbs"', false)
            ->assertSeeInOrder(['thumbs__tab', 'aria-current="page"', 'Visits'], false)
            ->assertSee(route('admin.calendar'), false)
            ->assertSee(route('admin.bin'), false);
    }

    public function test_phones_get_a_dock_with_ask_stacy_and_an_index(): void
    {
        $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()
            ->assertSee('class="dock"', false)
            ->assertSee('dock__stacy', false)
            ->assertSee('id="sheet-index"', false)
            ->assertSee('id="sheet-waiting"', false)
            ->assertSee('The index')
            ->assertDontSee('tabbar', false);
    }

    public function test_inside_a_chapter_its_pages_become_tabs_on_a_phone(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.entries', ['posts', 'status' => 'draft']))->assertOk();

        $page->assertSee('class="chapter-tabs"', false);
        $this->assertMatchesRegularExpression('/class="chapter-tabs__tab"[^>]*aria-current="page"[^>]*>\s*Drafts/', $page->getContent());
    }

    public function test_invite_someone_opens_the_invitation_straight_away_for_admins_only(): void
    {
        $this->actingAs($this->admin)->get(route('admin.people', ['invite' => 1]))->assertOk()->assertSee('id="invite-name"', false);

        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.people', ['invite' => 1]))->assertOk()->assertDontSee('id="invite-name"', false);
    }

    public function test_visits_shows_when_people_read_and_on_what(): void
    {
        DB::table('visit_hours')->insert(['day' => now()->toDateString(), 'hour' => 9, 'views' => 4]);
        DB::table('device_stats')->insert([
            ['day' => now()->toDateString(), 'device' => 'phone', 'views' => 3],
            ['day' => now()->toDateString(), 'device' => 'computer', 'views' => 1],
        ]);

        $this->actingAs($this->admin)->get(route('admin.visits'))->assertOk()
            ->assertSee('When people read')
            ->assertSee('week-heat__cell', false)
            ->assertSee('Phones 75%, computers 25%, tablets 0%');
    }
}
