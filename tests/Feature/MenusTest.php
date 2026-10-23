<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Booklet\Support\MenuOutline;
use App\Booklet\Support\Menus;
use App\Booklet\Support\PageTree;
use App\Livewire\Admin\Menus as MenusScreen;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Site menus with things under things: the outline moves, the editor,
 * "follow the page tree", drop-downs on the site and the live preview.
 */
class MenusTest extends TestCase
{
    use RefreshDatabase;

    protected function book(): User
    {
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);

        return User::factory()->create(['role' => 'admin']);
    }

    /** Labels and depths, to compare outlines at a glance: ['A', '-B', '--C']. */
    protected function shape(array $rows): array
    {
        return array_map(fn ($r) => str_repeat('-', $r['depth']).($r['title'] ?? $r['url']), $rows);
    }

    protected function outline(array $shape): array
    {
        return MenuOutline::normalize(array_map(function ($line) {
            $depth = strlen($line) - strlen(ltrim($line, '-'));

            return ['depth' => $depth, 'url' => '/'.strtolower(ltrim($line, '-')), 'title' => ltrim($line, '-')];
        }, $shape));
    }

    public function test_an_outline_goes_to_a_tree_and_back(): void
    {
        $tree = [
            ['url' => '/a', 'title' => 'A', 'children' => [
                ['url' => '/b', 'title' => 'B', 'children' => [['url' => '/c', 'title' => 'C']]],
            ]],
            ['url' => '/d', 'title' => 'D', 'follow' => true],
        ];

        $rows = MenuOutline::fromTree($tree);
        $this->assertSame(['A', '-B', '--C', 'D'], $this->shape($rows));
        $this->assertSame($tree, MenuOutline::toTree($rows));
    }

    public function test_moving_a_row_takes_its_children_and_never_goes_too_deep(): void
    {
        $rows = $this->outline(['A', '-B', '--C', 'D', 'E']);

        // B (with C) dropped before E at the top level.
        $this->assertSame(['A', 'D', 'B', '-C', 'E'], $this->shape(MenuOutline::place($rows, 1, 4, 0)));

        // B can't go under D at depth 2: with C under it, that would be four levels.
        $this->assertSame(['A', 'D', '-B', '--C', 'E'], $this->shape(MenuOutline::place($rows, 1, 4, 2)));

        // Nothing can be dropped inside itself.
        $this->assertSame($this->shape($rows), $this->shape(MenuOutline::place($rows, 0, 2, 1)));

        // Hang E under D, then bring it back.
        $nested = MenuOutline::indent($rows, 4);
        $this->assertSame(['A', '-B', '--C', 'D', '-E'], $this->shape($nested));
        $this->assertSame(['A', '-B', '--C', 'D', 'E'], $this->shape(MenuOutline::outdent($nested, 4)));

        // Lifting C puts it after the branch it was in (B's), under A.
        $this->assertSame(['A', '-B', '-C', 'D', 'E'], $this->shape(MenuOutline::outdent($rows, 2)));

        // Up and down stay among siblings.
        $this->assertSame(['D', 'A', '-B', '--C', 'E'], $this->shape(MenuOutline::up($rows, 3)));
        $this->assertSame(['D', 'A', '-B', '--C', 'E'], $this->shape(MenuOutline::down($rows, 0)));
        $this->assertTrue(MenuOutline::isFirst($rows, 1));
        $this->assertTrue(MenuOutline::isLast($rows, 4));

        // Removing A lifts what was under it.
        $this->assertSame(['B', '-C', 'D', 'E'], $this->shape(MenuOutline::remove($rows, 0)));
    }

    public function test_the_editor_nests_undoes_and_saves_a_tree(): void
    {
        $user = $this->book();
        Menus::save('header', [['url' => '/a', 'title' => 'A'], ['url' => '/b', 'title' => 'B'], ['url' => '/c', 'title' => 'C']]);

        $screen = Livewire::actingAs($user)->test(MenusScreen::class)
            ->call('nest', 1)                      // B under A
            ->call('drop', 2, 2, 2)                // C under B
            ->assertSet('dirty', true);

        $this->assertSame(['A', '-B', '--C'], $this->shape($screen->get('rows')));

        $screen->call('undo');
        $this->assertSame(['A', '-B', 'C'], $this->shape($screen->get('rows')));

        $screen->call('save')->assertDispatched('stamp');

        $this->assertSame([
            ['url' => '/a', 'title' => 'A', 'children' => [['url' => '/b', 'title' => 'B']]],
            ['url' => '/c', 'title' => 'C'],
        ], Menus::items('header'));
    }

    public function test_the_site_shows_drop_downs(): void
    {
        $this->book();
        Menus::save('header', [
            ['url' => '/shop', 'title' => 'Shop', 'children' => [
                ['url' => '/shop/prints', 'title' => 'Prints', 'children' => [['url' => '/shop/prints/a3', 'title' => 'A3 prints']]],
            ]],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('menu__item--parent', false)
            ->assertSee('aria-controls="menu-sub-0"', false)
            ->assertSee('More under Shop')
            ->assertSee('Prints')
            ->assertSee('A3 prints');
    }

    public function test_a_followed_page_brings_its_published_sub_pages(): void
    {
        $this->book();
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $team = Booklet::entries()->make(['collection' => 'pages', 'slug' => 'team'], ['title' => 'Our team'])->save();
        $secret = Booklet::entries()->make(['collection' => 'pages', 'slug' => 'secret', 'published' => false], ['title' => 'Secret plans'])->save();
        PageTree::for('pages')->place($team->id(), $about->id())->save();
        PageTree::for('pages')->place($secret->id(), $about->id())->save();

        Menus::save('header', [['entry' => $about->id(), 'follow' => true]]);

        $menu = app(Site::class)->menu('header');
        $this->assertSame(['Our team'], array_column($menu[0]['children'], 'label'));
        $this->assertTrue($menu[0]['children'][0]['followed']);

        // The editor marks the row, and its drawer lists what comes along.
        $screen = Livewire::actingAs(User::first())->test(MenusScreen::class)->assertSee('+ sub-pages')->assertDontSee('Bring its sub-pages along');
        $screen->call('edit', $screen->get('rows')[0]['key'])->assertSee('Bring its sub-pages along')->assertSee('Our team');
    }

    public function test_the_live_preview_is_only_for_the_person_arranging(): void
    {
        $user = $this->book();
        Menus::save('header', [['url' => '/saved', 'title' => 'Saved link']]);

        Livewire::actingAs($user)->test(MenusScreen::class)
            ->set('linkLabel', 'Not yet saved')
            ->set('linkUrl', '/unsaved')
            ->call('addLink')
            ->assertDispatched('menu-preview');

        $this->assertNotNull(Cache::get(Site::menuPreviewKey($user->id)));

        // Their preview shows it; the plain site (and anyone else) doesn't.
        $this->actingAs($user)->get('/?menu_preview=1')->assertSee('Not yet saved');
        $this->actingAs($user)->get('/')->assertDontSee('Not yet saved');

        $writer = User::factory()->create(['role' => 'writer']);
        Cache::put(Site::menuPreviewKey($writer->id), ['header' => [['url' => '/x', 'title' => 'Sneaky']]]);
        $this->actingAs($writer)->get('/?menu_preview=1')->assertDontSee('Sneaky');
    }

    public function test_the_footer_groups_links_when_they_have_children(): void
    {
        $this->book();
        Menus::save('footer', [
            ['url' => '/help', 'title' => 'Help', 'children' => [['url' => '/help/faq', 'title' => 'Questions']]],
            ['url' => '/legal', 'title' => 'Legal'],
        ]);

        $this->get('/')->assertSee('colophon__groups', false)->assertSee('Questions');
    }

    public function test_a_top_item_can_open_a_wide_panel(): void
    {
        $user = $this->book();
        Menus::save('header', [
            ['url' => '/shop', 'title' => 'Shop', 'children' => [
                ['url' => '/shop/prints', 'title' => 'Prints'],
                ['url' => '/shop/zines', 'title' => 'Zines', 'children' => [['url' => '/shop/zines/old', 'title' => 'Back issues']]],
            ]],
        ]);

        $screen = Livewire::actingAs($user)->test(MenusScreen::class)
            ->call('setOption', 0, 'mega', true)
            ->call('setOption', 0, 'blurb', 'Small batches, <b>made here</b>.')
            ->call('setOption', 0, 'latest', true)
            ->call('setOption', 1, 'mega', true)              // only a top item can
            ->call('setOption', 0, 'picture', 'not/there.jpg') // only a picture that exists
            ->call('setOption', 1, 'url', 'javascript:alert(1)')
            ->assertHasErrors('rows.1.url');

        $rows = $screen->get('rows');
        $this->assertTrue($rows[0]['mega']);
        $this->assertSame('Small batches, made here.', $rows[0]['blurb']);
        $this->assertFalse($rows[1]['mega']);
        $this->assertSame('', $rows[0]['picture']);
        $this->assertSame('/shop/prints', $rows[1]['url']);

        $screen->call('save');
        $this->assertTrue(Menus::items('header')[0]['mega']);

        $this->get('/')
            ->assertSee('menu__item--mega', false)
            ->assertSee('Small batches, made here.')
            ->assertSee('Back issues')
            ->assertSee('Newest');
    }

    public function test_moving_a_wide_panel_down_a_level_turns_it_back_into_a_list(): void
    {
        $this->book();
        Menus::save('header', [
            ['url' => '/a', 'title' => 'A'],
            ['url' => '/b', 'title' => 'B', 'mega' => true, 'blurb' => 'Hello panel', 'children' => [['url' => '/b/c', 'title' => 'C']]],
        ]);
        $this->get('/')->assertSee('Hello panel');

        Menus::save('header', [['url' => '/a', 'title' => 'A', 'children' => [
            ['url' => '/b', 'title' => 'B', 'mega' => true, 'blurb' => 'Hello panel'],
        ]]]);
        $this->get('/')->assertDontSee('Hello panel')->assertDontSee('menu__item--mega', false);
    }

    public function test_a_link_to_another_website_is_never_the_current_page(): void
    {
        $this->book();
        Menus::save('header', [['url' => 'https://shop.example.com', 'title' => 'Shop'], ['url' => '/', 'title' => 'Home']]);

        $menu = app(\App\Booklet\Site::class)->menu('header');
        $this->assertFalse($menu[0]['active'], 'shop.example.com has no path, but it isn’t our home page');
        $this->assertTrue($menu[1]['active']);
    }
}
