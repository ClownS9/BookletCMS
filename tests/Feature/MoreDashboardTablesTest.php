<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Activity;
use App\Livewire\Admin\Bin;
use App\Livewire\Admin\Inbox;
use App\Livewire\Admin\Media;
use App\Livewire\Admin\Readers;
use App\Livewire\Admin\Redirects;
use App\Livewire\Admin\Subscribers;
use App\Livewire\Admin\Taxonomies;
use App\Models\ActivityLog;
use App\Models\Reader;
use App\Models\Redirect;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\Inbox as Letters;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rest of the Dashboard layout's lists as tables: Readers, Subscribers,
 * Inbox, Redirects, the bin, Activity, Backups, Categories & tags, and Media's
 * list view. The Book layout keeps its pages.
 */
class MoreDashboardTablesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        YourBook::save($this->admin, ['layout' => 'dashboard']);
        $this->admin = $this->admin->fresh();
    }

    protected function reader(string $name, string $state = 'active'): Reader
    {
        return Reader::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x-long-password-1', 'state' => $state, 'email_verified_at' => now()]);
    }

    public function test_every_list_is_a_table_in_the_dashboard(): void
    {
        $this->reader('Siti');
        Subscriber::create(['email' => 'aminah@example.com', 'confirmed_at' => now(), 'token' => str_repeat('a', 40)]);
        Letters::receive(['name' => 'Mei', 'email' => 'mei@example.com', 'message' => 'Can I order a box?'], '/contact');
        Redirect::create(['from_path' => '/old', 'to_path' => '/new', 'status' => 301]);
        ActivityLog::record('edited', 'edits', 'entry', 'x', 'Kuih lapis');

        foreach (['admin.readers' => 'Siti', 'admin.subscribers' => 'aminah@example.com', 'admin.inbox' => 'Can I order a box?', 'admin.redirects' => '/old', 'admin.activity' => 'Kuih lapis', 'admin.taxonomies' => 'Tags'] as $route => $see) {
            $this->actingAs($this->admin)->get(route($route))->assertOk()->assertSee('class="dash-table', false)->assertSee($see);
        }
        $this->actingAs($this->admin)->get(route('admin.bin'))->assertOk()->assertSee('page--dash', false);
        $this->actingAs($this->admin)->get(route('admin.backups'))->assertOk()->assertSee('page--dash', false);
    }

    public function test_the_book_layout_keeps_its_pages(): void
    {
        YourBook::save($this->admin, ['layout' => 'book']);
        foreach (['admin.readers', 'admin.subscribers', 'admin.inbox', 'admin.redirects', 'admin.bin', 'admin.activity', 'admin.backups', 'admin.taxonomies'] as $route) {
            $this->actingAs($this->admin->fresh())->get(route($route))->assertOk()->assertDontSee('page--dash', false);
        }
    }

    public function test_readers_many_at_once_and_one_in_the_drawer(): void
    {
        $a = $this->reader('Ana', 'waiting');
        $b = $this->reader('Ben');

        $table = Livewire::actingAs($this->admin)->test(Readers::class)
            ->call('toggle', $b->id)->assertSee('drawer-stats', false)->assertSet('open', $b->id)
            ->call('closeDrawer')->assertSet('open', null)
            ->set('ticked', [$a->id])->call('doTicked', 'letIn');
        $this->assertNotSame('waiting', $a->fresh()->state);

        $table->set('ticked', [$b->id])->call('doTicked', 'block');
        $this->assertSame('blocked', $b->fresh()->state);
        $table->set('ticked', [$a->id, $b->id])->call('doTicked', 'remove');
        $this->assertSame(0, Reader::count());
    }

    public function test_subscribers_removed_many_at_once(): void
    {
        $one = Subscriber::create(['email' => 'one@example.com', 'confirmed_at' => now(), 'token' => str_repeat('b', 40)]);
        $two = Subscriber::create(['email' => 'two@example.com', 'confirmed_at' => now(), 'token' => str_repeat('c', 40)]);

        Livewire::actingAs($this->admin)->test(Subscribers::class)
            ->assertSee('one@example.com')->set('ticked', [$one->id, $two->id])->call('removeTicked');
        $this->assertSame(0, Subscriber::count());
    }

    public function test_inbox_archive_many_and_read_one_in_the_drawer(): void
    {
        $m = Letters::receive(['name' => 'Mei', 'email' => 'mei@example.com', 'message' => 'Hello'], '/contact');
        $n = Letters::receive(['name' => 'Kai', 'email' => 'kai@example.com', 'message' => 'Hi'], '/contact');

        Livewire::actingAs($this->admin)->test(Inbox::class)
            ->call('pick', $m->id())->assertSee('Write back to Mei')
            ->call('closeDrawer')->assertSet('selected', null)
            ->set('ticked', [$m->id(), $n->id()])->call('doTicked', 'archived');
        $this->assertSame('archived', Letters::status(Letters::find($m->id())));
        $this->assertSame('archived', Letters::status(Letters::find($n->id())));
    }

    public function test_redirects_sort_filter_and_remove_many(): void
    {
        $a = Redirect::create(['from_path' => '/qq-by-hand', 'to_path' => '/x', 'status' => 301, 'hits' => 5]);
        $b = Redirect::create(['from_path' => '/zz-automatic', 'to_path' => '/y', 'status' => 301, 'hits' => 50, 'automatic' => true]);

        $table = Livewire::actingAs($this->admin)->test(Redirects::class)->call('sortBy', 'hits')->assertSet('sort', '-hits');
        $this->assertSame([$b->id, $a->id], $table->viewData('redirects')->getCollection()->pluck('id')->all());
        $table->set('kind', 'hand')->assertSee('/qq-by-hand')->assertDontSee('/zz-automatic');
        $table->set('kind', '')->set('ticked', [$a->id, $b->id])->call('removeTicked');
        $this->assertSame(0, Redirect::count());
    }

    public function test_bin_put_back_many(): void
    {
        $post = Booklet::entries()->all()->first(fn ($e) => $e->collectionHandle() === 'posts');
        $item = \App\Support\Bin::entry($post);

        Livewire::actingAs($this->admin)->test(Bin::class)->assertSee($post->title())
            ->set('ticked', [$item->id])->call('doTicked', 'restore');
        Booklet::entries()->forget();
        $this->assertNotNull(Booklet::entries()->find($post->id()));
    }

    public function test_activity_search_and_a_stamp_in_the_drawer(): void
    {
        ActivityLog::record('edited', 'edits', 'entry', 'x', 'Kuih lapis');
        ActivityLog::record('edited', 'edits', 'entry', 'y', 'Pandan chiffon');
        $stamp = ActivityLog::query()->where('subject_label', 'Kuih lapis')->first();

        Livewire::actingAs($this->admin)->test(Activity::class)
            ->set('search', 'kuih')->assertSee('Kuih lapis')->assertDontSee('Pandan chiffon')
            ->set('opened', $stamp->id)->assertSee('A stamp on the card');
    }

    public function test_terms_remove_many_and_open_one(): void
    {
        Booklet::terms()->make(['taxonomy' => 'tags', 'slug' => 'pandan'], ['title' => 'Pandan'])->save();
        Booklet::terms()->make(['taxonomy' => 'tags', 'slug' => 'kuih'], ['title' => 'Kuih'])->save();

        Livewire::actingAs($this->admin)->test(Taxonomies::class)
            ->assertSee('#Pandan')->assertSet('drawer', false)
            ->call('choose', 'tags', 'pandan')->assertSet('drawer', true)->assertSee('Rename to')
            ->call('closeDrawer')
            ->set('ticked', ['pandan', 'kuih'])->call('removeTicked');
        $this->assertNull(Booklet::terms()->findBySlug('tags', 'pandan'));
        $this->assertNull(Booklet::terms()->findBySlug('tags', 'kuih'));
    }

    public function test_media_list_view_is_remembered(): void
    {
        Livewire::actingAs($this->admin)->test(Media::class)->call('setView', 'list')->assertSet('view', 'list');
        $this->assertSame('list', $this->admin->fresh()->preferences['media_view']);
        Livewire::actingAs($this->admin->fresh())->test(Media::class)->assertSet('view', 'list')
            ->call('sortBy', 'size')->assertSet('sort', '-size')
            ->set('noAlt', true)->assertSet('page', 1);
    }

    public function test_the_calendar_is_one_month_grid_with_the_day_beside_it(): void
    {
        $this->actingAs($this->admin)->get(route('admin.calendar'))->assertOk()
            ->assertSee('class="dcal__grid"', false)
            ->assertSee('Drafts on the desk')
            ->assertDontSee('class="page page--left', false)
            ->assertDontSee('role="grid"', false);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Admin\Calendar::class)
            ->call('pickDay', now()->startOfMonth()->format('Y-m-d'))
            ->assertSee(now()->startOfMonth()->translatedFormat('l, j F'));

        $person = User::factory()->create(['role' => 'admin']);
        $this->actingAs($person)->get(route('admin.calendar'))->assertOk()
            ->assertSee('page page--left', false)
            ->assertDontSee('dcal__grid', false);
    }

    public function test_settings_pages_mark_their_list_and_side_columns_and_every_card_has_a_title(): void
    {
        $this->actingAs($this->admin)->get(route('admin.collections'))->assertOk()->assertSee('page--list', false);
        $this->actingAs($this->admin)->get(route('admin.settings.webhooks'))->assertOk()->assertSee('page--list', false);
        $this->actingAs($this->admin)->get(route('admin.settings.updates'))->assertOk()->assertSee('page--aside', false);
        $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()
            ->assertSee('<h2 class="desk-card__title">Your site</h2>', false);

        // An empty card made a blank sliver on the cover page
        $this->assertDoesNotMatchRegularExpression('/<div class="desk-card__body">\s*<\/div>/',
            $this->actingAs($this->admin)->get(route('admin.settings.look'))->assertOk()->getContent());
    }

    public function test_the_top_bar_has_the_site_version_section_switcher_and_your_menu(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.people'))->assertOk()->getContent();
        $bar = substr($html, strpos($html, '<header class="deskbar">'), 12000);

        $this->assertStringContainsString('class="deskbar__badge"', $bar);
        $this->assertStringContainsString(config('booklet.version'), $bar);
        $this->assertStringContainsString('id="desk-switch-menu"', $bar);
        $this->assertStringContainsString('aria-controls="desk-me-menu"', $bar);
        $this->assertStringContainsString(route('logout'), $bar);
        $this->assertStringNotContainsString('sidebar__card', $html, 'your menu moved from the sidebar to the top bar');
        $this->assertSame(1, substr_count($html, 'id="desk-switch-menu"'));

        \Illuminate\Support\Facades\Cache::forever(\App\Support\Updates::CACHE, ['version' => '99.0', 'checked_at' => now()->toIso8601String()]);
        $this->actingAs($this->admin)->get(route('admin.people'))->assertSee('deskbar__badge is-new', false);

        $person = User::factory()->create(['role' => 'admin']);
        $this->actingAs($person)->get(route('admin.people'))->assertOk()->assertDontSee('<header class="deskbar">', false);
    }
}
