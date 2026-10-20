<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Entries;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use App\Support\VisitStats;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reading stats for each post: views, readers, read to the end, and where readers came from.
 */
class ReadingStatsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $postId;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->postId = Booklet::entries()->findBySlug('posts', 'hello-from-booklet')->id();
    }

    protected function read(array $headers = []): void
    {
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        $this->get('/blog/hello-from-booklet', $headers + ['User-Agent' => 'Mozilla/5.0 Reader'])->assertOk();
    }

    public function test_each_post_knows_how_it_is_read(): void
    {
        $this->read(['Referer' => 'https://www.google.com/search?q=kuih']);
        $this->read(['Referer' => 'https://www.google.com/search?q=kuih']); // the same reader again
        $this->read(['Referer' => 'https://l.facebook.com/', 'User-Agent' => 'Mozilla/5.0 Someone else']);
        $this->postJson('/read', ['path' => '/blog/hello-from-booklet'])->assertNoContent();

        $r = VisitStats::forEntry($this->postId);
        $this->assertSame(3, $r['views']);
        $this->assertSame(2, $r['readers'], 'the same reader twice in a day counts once');
        $this->assertSame(1, $r['reads']);
        $this->assertNull($r['rate'], 'too few views to say');
        $this->assertSame([['source' => 'Google', 'views' => 2], ['source' => 'Facebook', 'views' => 1]], $r['sources']);
        $this->assertSame(3, $r['total']);
    }

    public function test_pages_served_from_the_cache_still_count_for_their_post(): void
    {
        $this->read();                       // made and kept
        DB::table('page_stats')->delete();   // a new day, say
        DB::table('entry_sources')->delete();
        $this->read();                       // served from the cache

        $this->assertSame($this->postId, DB::table('page_stats')->value('entry_id'));
        $this->assertSame(1, VisitStats::forEntry($this->postId)['views']);
    }

    public function test_do_not_track_keeps_posts_out_of_it(): void
    {
        $this->read(['DNT' => '1']);
        $this->assertSame(0, DB::table('entry_sources')->count());
        $this->assertSame(0, VisitStats::forEntry($this->postId)['views']);
    }

    public function test_fingerprints_are_forgotten(): void
    {
        $this->read();
        $this->assertSame(1, DB::table('entry_reader_days')->count());
        $this->travel(3)->days();
        VisitStats::tidy();
        $this->assertSame(0, DB::table('entry_reader_days')->count());
        $this->assertSame(1, VisitStats::forEntry($this->postId, 30)['readers'], 'the count stays; only the fingerprints go');
    }

    public function test_the_editor_and_the_list_show_it(): void
    {
        foreach (range(1, 6) as $i) {
            $this->read(['User-Agent' => "Mozilla/5.0 Reader $i"]);
        }
        $this->postJson('/read', ['path' => '/blog/hello-from-booklet']);
        $this->postJson('/read', ['path' => '/blog/hello-from-booklet']);
        $this->postJson('/read', ['path' => '/blog/hello-from-booklet']);

        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $this->postId])
            ->assertSee('Readers')->assertSee('last 30 days')->assertSee('50%')->assertSee('Came from')->assertSee('Direct 6');

        Livewire::actingAs($this->admin)->test(Entries::class, ['collection' => 'posts'])
            ->assertSee('6 views in 30 days')->assertSee('50% to the end');

        $draft = Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Not out yet'])->save();
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $draft->id()])
            ->assertDontSee('last 30 days');
    }
}
