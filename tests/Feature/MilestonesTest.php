<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Contents;
use App\Models\Comment;
use App\Models\User;
use App\Support\Milestones;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Milestone stickers: worked out from the book itself, celebrated once.
 */
class MilestonesTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->nur);
    }

    protected function publish(int $n, Carbon $from, int $everyDays = 1, string $words = 'one two three'): void
    {
        foreach (range(0, $n - 1) as $i) {
            Booklet::entries()->make(
                ['collection' => 'posts', 'published' => true, 'date' => $from->copy()->addDays($i * $everyDays)->toDateTimeString()],
                ['title' => 'Post '.$i, 'content' => $words, 'author' => $this->nur->id],
            )->save();
        }
    }

    protected function sticker(string $key): array
    {
        return Milestones::for($this->nur)->firstWhere('key', $key);
    }

    public function test_posts_earn_stickers_on_the_day_of_the_post_that_got_there(): void
    {
        $this->publish(10, now()->subDays(20));

        $this->assertTrue($this->sticker('post-1')['earned']);
        $this->assertTrue($this->sticker('post-10')['earned']);
        $this->assertSame(now()->subDays(11)->toDateString(), $this->sticker('post-10')['at']->toDateString());

        $next = $this->sticker('post-25');
        $this->assertFalse($next['earned']);
        $this->assertSame([10, 25], [$next['have'], $next['goal']]);
    }

    public function test_only_your_own_published_posts_count(): void
    {
        Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Draft', 'content' => 'x', 'author' => $this->nur->id])->save();
        Booklet::entries()->make(['collection' => 'posts', 'published' => true, 'date' => now()->subDay()->toDateTimeString()], ['title' => 'Theirs', 'content' => 'x', 'author' => 999])->save();

        $this->assertFalse($this->sticker('post-1')['earned']);
    }

    public function test_words_are_counted_without_the_markdown(): void
    {
        $this->assertSame(5, Milestones::words("## A heading\n\n**Bold** words and ![a cat](cat.jpg)"));
        $this->assertSame(4, Milestones::words(['one two', ['three', 'four']]));

        $this->publish(2, now()->subDays(3), 1, str_repeat('word ', 5000));
        $this->assertTrue($this->sticker('words-10000')['earned']);
        $this->assertFalse($this->sticker('words-50000')['earned']);
    }

    public function test_four_weeks_running(): void
    {
        $monday = now()->startOfWeek()->subWeeks(10);
        $weeks = collect([0, 1, 2, 4, 5, 6, 7])->map(fn ($w) => $monday->copy()->addWeeks($w)->addDays(2));
        $streak = Milestones::streak($weeks);

        $this->assertSame(4, $streak['best']);
        $this->assertSame($weeks[6]->toDateString(), $streak['at']->toDateString(), 'the gap starts the count again');
        $this->assertSame(1, Milestones::streak(collect([$monday, $monday->copy()->addDay()]))['best'], 'twice in one week is one week');
    }

    public function test_book_stickers_from_comments_and_visits(): void
    {
        $this->assertFalse($this->sticker('comment-1')['earned']);

        $post = Booklet::entries()->whereCollection('posts')->first();
        Comment::create(['entry_id' => $post->id(), 'author_name' => 'A reader', 'body' => 'Lovely.', 'status' => 'approved']);
        DB::table('site_visits')->insert([
            ['day' => now()->subDays(2)->toDateString(), 'views' => 600],
            ['day' => now()->subDay()->toDateString(), 'views' => 500],
        ]);

        $this->assertTrue($this->sticker('comment-1')['earned']);
        $this->assertSame(now()->subDay()->toDateString(), $this->sticker('visits-1000')['at']->toDateString());
    }

    public function test_the_books_birthday(): void
    {
        $this->nur->forceFill(['created_at' => now()->subYear()->subDays(3)])->save();

        $this->assertTrue($this->sticker('birthday-1')['earned']);
        $this->assertFalse($this->sticker('birthday-2')['earned'], 'the next one to look forward to');
    }

    public function test_a_new_sticker_is_celebrated_once_on_contents(): void
    {
        $this->publish(1, now()->subDay());

        Livewire::test(Contents::class)->assertSet('celebrate.key', 'post-1')->assertSee('New sticker');
        Livewire::test(Contents::class)->assertSet('celebrate', null)->assertDontSee('New sticker');
    }

    public function test_old_news_and_switching_them_off_stay_quiet(): void
    {
        $this->publish(1, now()->subDays(Milestones::FRESH_DAYS + 5));
        $this->assertTrue(Milestones::fresh($this->nur)->isEmpty(), 'nothing to celebrate from long ago');

        $this->publish(9, now()->subDays(9));
        $this->assertTrue($this->sticker('post-10')['earned']);
        $this->nur->forceFill(['preferences' => ['stickers' => false]])->save();
        $this->assertTrue(Milestones::fresh($this->nur->fresh())->isEmpty());
        Livewire::test(Contents::class)->assertDontSee('Sticker book');
    }

    public function test_the_sticker_book_in_your_account(): void
    {
        $this->publish(1, now()->subDay());

        $this->get(route('admin.account'))->assertOk()->assertSee('Sticker book')->assertSee('First post')->assertSee('1 of 10');
    }
}
