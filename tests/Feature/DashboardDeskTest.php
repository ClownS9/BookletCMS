<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Entries;
use App\Models\User;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Dashboard layout as a plain CMS desk: a top bar, a light sidebar,
 * Contents as panels, and each dated chapter as a table to sort, filter
 * and tick. The Book layout is left exactly as it was.
 */
class DashboardDeskTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah']);
        YourBook::save($this->admin, ['layout' => 'dashboard']);
        $this->admin = $this->admin->fresh();
    }

    protected function write(string $title, string $date, ?User $by = null, bool $published = true)
    {
        return Booklet::entries()->make(
            ['collection' => 'posts', 'published' => $published, 'date' => $date],
            ['title' => $title, 'author' => ($by ?? $this->admin)->id],
        )->save();
    }

    public function test_contents_is_a_dashboard_of_panels_with_the_desk_bar(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk();

        $page->assertSee('class="deskbar"', false)
            ->assertSee('page--dash', false)
            ->assertSee('dash-figures', false)
            ->assertSee('Recently written')
            ->assertSee('Quick draft')
            ->assertSee(route('admin.stacy'), false)
            ->assertDontSee('class="toc toc--book"', false);

        // Phones keep their own top bar in this layout too.
        $page->assertSee('<header class="topbar">', false);
    }

    public function test_the_book_layout_is_unchanged(): void
    {
        $person = User::factory()->create(['role' => 'admin']);

        $this->actingAs($person)->get(route('admin.contents'))->assertOk()
            ->assertSee('class="toc toc--book"', false)
            ->assertDontSee('class="deskbar"', false)
            ->assertDontSee('page--dash', false);

        $this->actingAs($person)->get(route('admin.entries', 'posts'))->assertOk()
            ->assertSee('class="picks"', false)
            ->assertDontSee('class="dash-table"', false);
    }

    public function test_a_dated_chapter_is_a_table_and_sorts_by_title_and_date(): void
    {
        $this->write('Banana bread', '2026-10-01 09:00:00');
        $this->write('Apam balik', '2026-10-03 09:00:00');
        $this->write('Cendol', '2026-10-02 09:00:00');

        $this->actingAs($this->admin)->get(route('admin.entries', 'posts'))->assertOk()
            ->assertSee('class="dash-table"', false)
            ->assertSee('Apam balik');

        $list = Livewire::actingAs($this->admin)->test(Entries::class, ['collection' => 'posts'])
            ->call('sortBy', 'title')->assertSet('sort', 'title');
        $list->assertSeeInOrder(['Apam balik', 'Banana bread', 'Cendol']);

        $list->call('sortBy', 'title')->assertSet('sort', '-title')
            ->assertSeeInOrder(['Cendol', 'Banana bread', 'Apam balik']);

        // Dates start with the newest, then turn round.
        $list->call('sortBy', 'date')->assertSet('sort', '-date')
            ->assertSeeInOrder(['Apam balik', 'Cendol', 'Banana bread']);
        $list->call('sortBy', 'date')->assertSet('sort', 'date')
            ->assertSeeInOrder(['Banana bread', 'Cendol', 'Apam balik']);

        $list->call('sortBy', 'author')->assertStatus(422);
    }

    public function test_an_unknown_sort_in_the_address_is_ignored(): void
    {
        $this->actingAs($this->admin)->get(route('admin.entries', ['collection' => 'posts', 'sort' => 'password']))->assertOk();
        Livewire::withQueryParams(['sort' => '"><script>'])->actingAs($this->admin)
            ->test(Entries::class, ['collection' => 'posts'])->assertSet('sort', '');
    }

    public function test_ticking_rows_shows_what_to_do_with_them(): void
    {
        $one = $this->write('Kuih lapis', '2026-10-01 09:00:00', published: false);

        Livewire::actingAs($this->admin)->test(Entries::class, ['collection' => 'posts'])
            ->assertDontSee('dash-bulk', false)
            ->set('ticked', [$one->id()])
            ->assertSee('dash-bulk', false)
            ->assertSee('Publish')
            ->call('publishTicked');

        $this->assertSame('published', Booklet::entries()->find($one->id())->status());
    }

    public function test_a_rows_own_menu_moves_it_to_the_bin_and_a_writer_only_bins_their_own(): void
    {
        $mine = $this->write('Mine', '2026-10-01 09:00:00');
        Livewire::actingAs($this->admin)->test(Entries::class, ['collection' => 'posts'])->call('binOne', $mine->id());
        $this->assertNull(Booklet::entries()->find($mine->id()));

        $writer = User::factory()->create(['role' => 'writer']);
        YourBook::save($writer, ['layout' => 'dashboard']);
        $theirs = $this->write('Not yours', '2026-10-02 09:00:00');
        Livewire::actingAs($writer->fresh())->test(Entries::class, ['collection' => 'posts'])->call('binOne', $theirs->id());
        $this->assertNotNull(Booklet::entries()->find($theirs->id()));
    }

    public function test_status_tabs_and_page_numbers(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->write("Post $i", now()->subDays($i)->toDateTimeString());
        }

        $list = Livewire::actingAs($this->admin)->test(Entries::class, ['collection' => 'posts'])
            ->assertSee('dash-pages', false)
            ->assertSee('aria-current="page"', false);
        $list->call('turnTo', 2)->assertSet('page', 2);
        $list->set('status', 'draft')->assertSet('page', 1)->assertDontSee('dash-pages', false);
    }

    public function test_pages_keep_their_tree_in_the_dashboard_layout(): void
    {
        $this->actingAs($this->admin)->get(route('admin.entries', 'pages'))->assertOk()
            ->assertSee('role="tree"', false)
            ->assertDontSee('class="dash-table"', false);
    }

    public function test_the_sidebar_groups_content_tools_and_settings(): void
    {
        $this->actingAs($this->admin)->get(route('admin.media'))->assertOk()
            ->assertSee('id="sidebar-content"', false)
            ->assertSee('id="sidebar-tools"', false)
            ->assertSee('id="sidebar-back"', false)
            ->assertSee(route('admin.settings'), false);
    }
}
