<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use App\Models\User;
use App\Support\BookStats;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This week's figures count what really happened, and every chart in the
 * book grows into place when its page opens.
 */
class ChartsTest extends TestCase
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

    protected function figure(string $key): array
    {
        return collect(BookStats::week(Booklet::entries()->all()))->firstWhere('key', $key);
    }

    public function test_posts_out_counts_posts_published_this_week(): void
    {
        $before = $this->figure('published')['n']; // the starter post went out today

        Booklet::entries()->make(['collection' => 'posts', 'slug' => 'kuih-today', 'published' => true, 'date' => now()->subHour()->toDateTimeString()], ['title' => 'Kuih today'])->save();
        Booklet::entries()->make(['collection' => 'posts', 'slug' => 'still-a-draft', 'published' => false, 'date' => now()->subHour()->toDateTimeString()], ['title' => 'Still a draft'])->save();
        Booklet::entries()->make(['collection' => 'posts', 'slug' => 'next-week', 'published' => true, 'date' => now()->addDays(3)->toDateTimeString()], ['title' => 'Next week'])->save();
        Booklet::entries()->make(['collection' => 'posts', 'slug' => 'last-week', 'published' => true, 'date' => now()->subDays(9)->toDateTimeString()], ['title' => 'Last week'])->save();
        Booklet::entries()->forget();

        $out = $this->figure('published');
        $this->assertSame($before + 1, $out['n']);
        $this->assertSame($before + 1, end($out['days'])['n']); // today
        $this->assertStringNotContainsString('none yet', $out['delta']);
        $this->assertSame(route('admin.entries', 'posts'), $out['href']);
    }

    public function test_comments_count_this_week_without_spam(): void
    {
        $post = Booklet::entries()->all()->first(fn ($e) => $e->collectionHandle() === 'posts');
        $before = $this->figure('comments')['n'];
        foreach (['approved', 'waiting', 'spam'] as $status) {
            Comment::create(['entry_id' => $post->id(), 'author_name' => 'Hana', 'author_email' => 'hana@example.com', 'body' => 'Sedap!', 'status' => $status]);
        }

        $comments = $this->figure('comments');
        $this->assertSame($before + 2, $comments['n']); // the spam one doesn't count
        $this->assertSame('up', $comments['trend']);
    }

    public function test_an_empty_day_is_an_empty_stub_and_each_bar_says_its_day(): void
    {
        YourBook::save($this->admin, ['layout' => 'dashboard']);
        \Illuminate\Support\Facades\DB::table('site_visits')->insert(['day' => now()->toDateString(), 'views' => 12, 'visitors' => 5]);

        $page = $this->actingAs($this->admin->fresh())->get(route('admin.contents'))->assertOk()->getContent();

        $this->assertStringContainsString('class="is-zero" style="--h: 0%; --i: 0"', $page); // no visits six days ago
        $this->assertStringContainsString('style="--h: 100%; --i: 6"', $page);               // today's visits
        $this->assertStringContainsString('href="'.route('admin.entries', 'posts').'"', $page); // Posts out opens the posts
        $this->assertStringContainsString('pathLength="1"', $page); // the month's line draws itself
    }

    public function test_every_chart_grows_in_and_stays_still_for_reduced_motion(): void
    {
        $css = file_get_contents(public_path('booklet/admin.css'));
        $start = strpos($css, '/* Charts come in');
        $this->assertNotFalse($start);
        $block = substr($css, $start);

        foreach (['.dash-figure__bars span', '.spark__bar', '.hour-bars span', '.visit-chart__bar i', '.share-strip', '.device-strip',
            '.visit-pages__depth::after', '.visit-sources li::before', '.sticker-book__bar span', '.first-steps__bar span', '.most-read__ink',
            '.week-heat__cell', '.pub-grid__day', '.ink-chart__wash', '.ink-chart__line', '.ink-chart__peak'] as $chart) {
            $this->assertMatchesRegularExpression('/'.preg_quote($chart, '/').'[^{]*\{[^}]*animation/', $block, $chart.' grows in');
        }
        $this->assertStringContainsString('prefers-reduced-motion', $block);
    }
}
