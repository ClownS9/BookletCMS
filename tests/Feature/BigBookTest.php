<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Entries;
use App\Models\User;
use App\Support\Milestones;
use App\Support\Remember;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A book with many posts stays quick: each entry is read once a request,
 * the list shows a page at a time, and nothing reads every entry for
 * something it can ask for directly.
 */
class BigBookTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin']);
    }

    protected function posts(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Booklet::entries()->make(
                ['collection' => 'posts', 'published' => true, 'date' => now()->subHours($i)->toDateTimeString()],
                ['title' => "Post number $i", 'content' => 'One two three four.', 'author' => $this->nur->id],
            )->save();
        }
    }

    public function test_entries_are_read_once_a_request_and_saving_shows_at_once(): void
    {
        $this->posts(3);
        Booklet::entries()->forget();

        DB::enableQueryLog();
        $first = Booklet::entries()->all();
        Booklet::entries()->all();
        Booklet::entries()->whereCollection('posts');
        $reads = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/from "entries"/', $q['query']))->count();
        $this->assertSame(1, $reads, 'all(), all() again and a chapter: one read');

        // A new list each time: sorting one doesn't sort the others.
        $first->sortBy(fn ($e) => $e->title())->values();
        $this->assertNotSame($first, Booklet::entries()->all());

        // Saved or deleted: the next all() has it.
        $new = Booklet::entries()->make(['collection' => 'posts', 'published' => true], ['title' => 'Fresh one'])->save();
        $this->assertTrue(Booklet::entries()->all()->contains(fn ($e) => $e->id() === $new->id()));
        Booklet::entries()->delete($new);
        $this->assertFalse(Booklet::entries()->all()->contains(fn ($e) => $e->id() === $new->id()));
    }

    public function test_slugs_stay_unique_without_reading_the_chapter(): void
    {
        $this->posts(2);
        Booklet::entries()->forget();
        $a = Booklet::entries()->make(['collection' => 'posts', 'published' => true], ['title' => 'Same name'])->save();
        $b = Booklet::entries()->make(['collection' => 'posts', 'published' => true], ['title' => 'Same name'])->save();
        $c = Booklet::entries()->make(['collection' => 'posts', 'published' => true], ['title' => 'Same name'])->save();

        $this->assertSame(['same-name', 'same-name-2', 'same-name-3'], [$a->slug(), $b->slug(), $c->slug()]);
        // Saving again keeps its own address.
        $this->assertSame('same-name-2', $b->set('content', 'More')->save()->slug());
    }

    public function test_the_list_shows_fifty_at_a_time(): void
    {
        $this->posts(Entries::PER_PAGE + 5);

        $list = Livewire::actingAs($this->nur)->test(Entries::class, ['collection' => 'posts'])
            ->assertSee('Post number 1')->assertSee('Post number 49')->assertDontSee('Post number 51')
            ->assertSee('Page 1 of 2');

        $list->call('turnTo', 2)->assertSee('Post number 52')->assertDontSee('Post number 49')
            ->assertSet('page', 2);
        $list->set('search', 'number 5')->assertSet('page', 1)->assertDontSee('Page 1 of 2');

        // Opened at a post far down the list: its page.
        $far = Booklet::entries()->whereCollection('posts')->first(fn ($e) => $e->title() === 'Post number 53');
        Livewire::actingAs($this->nur)->withQueryParams(['entry' => $far->id()])->test(Entries::class, ['collection' => 'posts'])
            ->assertSet('page', 2)->assertSet('selected', $far->id());
    }

    public function test_word_counts_are_kept_and_redone_when_the_words_change(): void
    {
        $this->posts(2);
        $post = Booklet::entries()->whereCollection('posts')->first(fn ($e) => $e->title() === 'Post number 1');

        Milestones::for($this->nur);
        $this->assertSame(4, Cache::get(Milestones::WORDS_KEY)[$post->id()][1] ?? null);

        $post->set('content', str_repeat('word ', 120))->save();
        Milestones::for($this->nur);
        $this->assertSame(120, Cache::get(Milestones::WORDS_KEY)[$post->id()][1] ?? null, 'counted again once its words changed');

        // Asked twice in one request (the contents page does): the posts are gone through once.
        Remember::forget();
        $looks = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Cache\Events\RetrievingKey::class, function ($e) use (&$looks) {
            $looks += $e->key === Milestones::WORDS_KEY ? 1 : 0;
        });
        Milestones::for($this->nur);
        Milestones::for($this->nur);
        $this->assertSame(1, $looks);
    }

    public function test_recent_edits_are_asked_for_not_read_whole(): void
    {
        $this->posts(4);
        $this->travel(1)->minutes();
        $edited = Booklet::entries()->whereCollection('posts')->last();
        $edited->set('content', 'Changed.')->save();
        Booklet::entries()->forget();

        $recent = app(\App\Support\SearchIndex::class)->recentlyEdited(2);
        $this->assertCount(2, $recent);
        $this->assertStringContainsString($edited->title(), json_encode($recent));
    }
}
