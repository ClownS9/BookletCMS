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

    public function test_pages_are_a_tree_table_with_their_own_row_actions(): void
    {
        $about = Booklet::entries()->make(['collection' => 'pages', 'slug' => 'about-us', 'published' => true], ['title' => 'About us'])->save();
        $team = Booklet::entries()->make(['collection' => 'pages', 'slug' => 'our-people', 'published' => true], ['title' => 'Our people'])->save();
        \App\Booklet\Support\PageTree::for('pages')->place($about->id())->save();
        \App\Booklet\Support\PageTree::for('pages')->place($team->id())->save();

        $this->actingAs($this->admin)->get(route('admin.entries', 'pages'))->assertOk()
            ->assertSee('class="dash-table"', false)
            ->assertSee('In the menu')
            ->assertDontSee('role="tree"', false);

        $list = Livewire::actingAs($this->admin)->test(Entries::class, ['collection' => 'pages']);

        $list->call('menuFor', $team->id());
        $this->assertTrue(\App\Booklet\Support\Menus::has('header', $team->id()));

        $list->call('templateFor', $team->id(), 'landing');
        $this->assertSame('landing', Booklet::entries()->find($team->id())->get('template'));

        // Put "Our people" under "About us": the picker opens, then the page moves and shows indented.
        $list->call('startMove', $team->id())->assertSet('moving', $team->id())->assertSee('move-parent', false)
            ->call('moveTo', $about->id())->assertSet('moving', null)->assertSee('dash-table__indent', false);
        $this->assertContains($team->id(), \App\Booklet\Support\PageTree::for('pages')->descendantsOf($about->id()));

        $list->call('templateFor', $team->id(), 'poster')->assertStatus(422);
    }

    public function test_a_writer_cannot_rearrange_the_page_tree(): void
    {
        $page = Booklet::entries()->make(['collection' => 'pages', 'published' => true], ['title' => 'Somewhere'])->save();
        $writer = User::factory()->create(['role' => 'writer']);
        YourBook::save($writer, ['layout' => 'dashboard']);

        Livewire::actingAs($writer->fresh())->test(Entries::class, ['collection' => 'pages'])
            ->call('startMove', $page->id())->assertStatus(403);
        Livewire::actingAs($writer->fresh())->test(Entries::class, ['collection' => 'pages'])
            ->call('menuFor', $page->id())->assertStatus(403);
    }

    public function test_every_page_gets_a_header_with_breadcrumbs_and_the_book_keeps_its_own(): void
    {
        $this->actingAs($this->admin)->get(route('admin.backups'))->assertOk()
            ->assertSee('class="page-head"', false)
            ->assertSee('page-head__crumbs', false);

        $person = User::factory()->create(['role' => 'admin']);
        $this->actingAs($person)->get(route('admin.backups'))->assertOk()
            ->assertSee('class="page-head"', false)
            ->assertDontSee('page-head__crumbs', false)
            ->assertSee('class="back"', false);
    }

    public function test_the_editor_has_its_title_status_and_buttons_on_top(): void
    {
        $post = $this->write('Teh tarik at home', '2026-10-01 09:00:00', published: false);

        $this->actingAs($this->admin)->get(route('admin.entries.edit', ['posts', $post->id()]))->assertOk()
            ->assertSee('page-head--editor', false)
            ->assertSee('Teh tarik at home')
            ->assertSee('Save draft')
            ->assertSee('editor-save is-desk', false);
    }

    public function test_pages_are_made_of_cards_on_the_desk_and_unchanged_in_the_book(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()
            ->assertSee('class="desk-card"', false);

        $person = User::factory()->create(['role' => 'admin']);
        $this->actingAs($person)->get(route('admin.settings'))->assertOk()
            ->assertDontSee('desk-card', false)
            ->assertSee('class="ribbon"', false);
    }

    public function test_the_editor_has_labelled_fields_and_side_cards(): void
    {
        $post = $this->write('Kek lapis', '2026-10-01 09:00:00', published: false);

        $this->actingAs($this->admin)->get(route('admin.entries.edit', ['posts', $post->id()]))->assertOk()
            ->assertSee('editor-box', false)
            ->assertSee('editor-slug', false)
            ->assertSee('<h2 class="desk-card__title">Publishing</h2>', false)
            ->assertDontSee('<div class="toolbar">', false);

        $person = User::factory()->create(['role' => 'admin']);
        $this->actingAs($person)->get(route('admin.entries.edit', ['posts', $post->id()]))->assertOk()
            ->assertSee('<div class="toolbar">', false)
            ->assertDontSee('editor-box', false)
            ->assertDontSee('desk-card', false);
    }

    public function test_the_sidebar_folds_to_a_rail_and_stays_that_way_for_that_person(): void
    {
        $this->actingAs($this->admin)->get(route('admin.media'))->assertOk()
            ->assertSee('deskbar__fold', false)
            ->assertSee('aria-controls="desk-sidebar"', false)
            ->assertDontSee('desk-narrow', false);

        $this->actingAs($this->admin)->postJson(route('admin.sidebar'), ['narrow' => true])->assertOk()->assertJson(['narrow' => true]);
        $this->assertTrue($this->admin->fresh()->preferences['sidebar_narrow']);
        $this->assertMatchesRegularExpression('/<body class="[^"]*desk-narrow/', $this->actingAs($this->admin->fresh())->get(route('admin.media'))->getContent());

        // Someone else's sidebar is their own.
        $other = User::factory()->create(['role' => 'editor']);
        YourBook::save($other, ['layout' => 'dashboard']);
        $this->assertDoesNotMatchRegularExpression('/<body class="[^"]*desk-narrow/', $this->actingAs($other->fresh())->get(route('admin.media'))->getContent());

        // Without a value it flips; open again leaves nothing behind.
        $this->actingAs($this->admin->fresh())->post(route('admin.sidebar'))->assertRedirect();
        $this->assertArrayNotHasKey('sidebar_narrow', (array) $this->admin->fresh()->preferences);
    }

    public function test_the_book_layout_never_folds_a_sidebar(): void
    {
        $person = User::factory()->create(['role' => 'admin', 'preferences' => ['sidebar_narrow' => true]]);
        $this->actingAs($person)->get(route('admin.media'))->assertOk()->assertDontSee('desk-narrow', false)->assertDontSee('deskbar__fold', false);
    }

    public function test_ask_stacy_buttons_open_her_docked_card(): void
    {
        $this->actingAs($this->admin)->get(route('admin.media'))->assertOk()
            ->assertSee('class="deskbar__stacy" data-stacy-dock aria-controls="orb-card"', false)
            ->assertSee('class="dock__stacy" data-stacy-dock aria-controls="orb-card"', false);

        $person = User::factory()->create(['role' => 'admin']);
        $this->actingAs($person)->get(route('admin.media'))->assertOk()
            ->assertSee('data-stacy-dock aria-controls="orb-card"', false);
    }

    public function test_the_sidebar_has_sub_menus_and_keeps_tools_short(): void
    {
        $this->actingAs($this->admin)->get(route('admin.calendar'))->assertOk();
        $side = \App\Support\AdminNav::sidebar($this->admin);
        $posts = collect($side['content'])->firstWhere('key', 'posts');

        $this->assertSame(['calendar', 'taxonomies', 'print'], array_column($posts['subs'], 'key'));
        $this->assertTrue($posts['open']); // you're on one of its pages
        $this->assertNotContains('calendar', array_column($side['tools'], 'key'));
        $this->assertContains('look', array_column($side['settings'][0]['subs'], 'key'));

        $page = $this->actingAs($this->admin)->get(route('admin.calendar'))->getContent();
        $this->assertStringContainsString('aria-controls="sub-posts"', $page);
        $this->assertMatchesRegularExpression('/<ul class="sidebar__sub" id="sub-posts"/', $page);
    }

    public function test_what_someone_hid_stays_out_of_the_sub_menus(): void
    {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Admin\YourMenu::class)->call('place', 'calendar', 'hidden');
        $this->actingAs($this->admin->fresh())->get(route('admin.media'));
        $posts = collect(\App\Support\AdminNav::sidebar($this->admin->fresh())['content'])->firstWhere('key', 'posts');
        $this->assertNotContains('calendar', array_column($posts['subs'], 'key'));
    }

    public function test_a_writer_sees_no_sub_pages_they_cannot_open(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);
        YourBook::save($writer, ['layout' => 'dashboard']);
        $this->actingAs($writer->fresh())->get(route('admin.media'))->assertOk()
            ->assertDontSee(route('admin.menus'), false)
            ->assertDontSee(route('admin.redirects'), false);
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
