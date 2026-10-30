<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Activity;
use App\Livewire\Admin\Bin;
use App\Livewire\Admin\Comments;
use App\Livewire\Admin\Entries;
use App\Livewire\Admin\Redirects;
use App\Livewire\Admin\Subscribers;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Redirect;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One pager on every list: nothing old out of reach, rows a page kept for
 * each person and list, the page in the address, and back to page one when
 * a filter changes.
 */
class PagerTest extends TestCase
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

    protected function comments(int $n): void
    {
        $post = Booklet::entries()->all()->first(fn ($e) => $e->collectionHandle() === 'posts');
        Comment::query()->delete();
        for ($i = 1; $i <= $n; $i++) {
            $c = Comment::create(['entry_id' => $post->id(), 'author_name' => "Reader $i", 'author_email' => "r$i@example.com", 'body' => "Note number $i", 'status' => 'waiting']);
            $c->forceFill(['created_at' => now()->subMinutes($n - $i)])->save();
        }
    }

    public function test_older_comments_are_no_longer_out_of_reach(): void
    {
        $this->comments(130); // the pile used to stop at 100

        $list = Livewire::actingAs($this->admin)->test(Comments::class)
            ->assertSee('Showing 1–25 of 130')
            ->assertSee('Note number 130');
        $list->call('turnTo', 6)->assertSee('Note number 1')->assertSee('Showing 126–130 of 130');
        $list->call('turnTo', 99)->assertSet('page', 6); // past the end: the last page
        $list->set('status', 'spam')->assertSet('page', 1);
    }

    public function test_rows_a_page_are_kept_for_each_person_and_list(): void
    {
        $this->comments(130);

        Livewire::actingAs($this->admin)->test(Comments::class)
            ->call('turnTo', 3) // rows 51–75
            ->call('setPerPage', 50)
            ->assertSet('page', 2) // the first row you saw is still in view
            ->assertSee('Showing 51–100 of 130');
        $this->assertSame(50, $this->admin->fresh()->preferences['per_page']['comments']);

        Livewire::actingAs($this->admin->fresh())->test(Comments::class)->assertSet('perPage', 50);
        Livewire::actingAs($this->admin->fresh())->test(Bin::class)->assertSet('perPage', 25); // each list its own

        Livewire::actingAs($this->admin->fresh())->test(Comments::class)->call('setPerPage', 7)->assertSet('perPage', 50); // only the sizes offered
    }

    public function test_the_page_is_in_the_address(): void
    {
        $this->comments(60);
        Livewire::withQueryParams(['page' => 3])->actingAs($this->admin)->test(Comments::class)
            ->assertSet('page', 3)->assertSee('Showing 51–60 of 60');
    }

    public function test_redirects_bin_subscribers_and_activity_turn_pages(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Redirect::create(['from_path' => "/old-$i", 'to_path' => '/new', 'status' => 301]);
            Subscriber::create(['email' => "s$i@example.com", 'confirmed_at' => now()]);
            ActivityLog::record('edited', 'edits', 'entry', "e$i", "Entry $i");
        }

        Livewire::actingAs($this->admin)->test(Redirects::class)->assertSee('Showing 1–25 of 30')->call('turnTo', 2)->assertSee('Showing 26–30 of 30');
        Livewire::actingAs($this->admin)->test(Subscribers::class)->assertSee('Showing 1–25 of 30')->set('search', 's1')->assertSet('page', 1);
        Livewire::actingAs($this->admin)->test(Activity::class)->call('turnTo', 2)->assertSet('page', 2)->set('kind', 'edits')->assertSet('page', 1);
    }

    public function test_ticks_reach_across_pages(): void
    {
        foreach (range(1, 30) as $i) {
            Booklet::entries()->make(['collection' => 'posts', 'slug' => "post-$i", 'published' => true, 'date' => now()->subDays($i)->toDateTimeString()], ['title' => "Post $i"])->save();
        }
        Booklet::entries()->forget();
        YourBook::save($this->admin, ['layout' => 'dashboard']);

        $table = Livewire::actingAs($this->admin->fresh())->test(Entries::class, ['collection' => 'posts'])->call('setPerPage', 25);
        $ids = $table->viewData('pageIds');
        $table->call('tickAll', $ids)->assertSee('Choose all');
        $table->call('tickThese', $table->viewData('matchingIds'))->assertCount('ticked', count($table->viewData('matchingIds')));
        $table->call('turnTo', 2)->assertSee('on this page');
    }

    public function test_the_pager_turns_with_the_keyboard_and_is_on_the_shortcuts_card(): void
    {
        $this->assertStringContainsString('data-pager-next', file_get_contents(resource_path('views/components/admin/pager.blade.php')));
        $this->assertStringContainsString("'ArrowLeft'", file_get_contents(public_path('booklet/booklet.js')));
        $this->actingAs($this->admin)->get(route('admin.contents'))->assertSee('Turn a list’s pages');
    }
}
