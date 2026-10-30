<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\MakeItYours;
use App\Livewire\Admin\YourMenu;
use App\Models\User;
use App\Support\AdminNav;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Make it yours (layout, theme, fonts, spacing, text size, night) and Your
 * menu: each person's own, and nobody else's.
 */
class MakeItYoursTest extends TestCase
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

    // Make it yours -------------------------------------------------------------

    public function test_everyone_starts_with_booklets_own_look(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk();

        $page->assertSee('class="flags"', false)->assertDontSee('class="sidebar"', false)->assertDontSee('fonts.googleapis.com/css2?family=Lora', false);
        $this->assertMatchesRegularExpression('/<body class="layout-book"/', $page->getContent()); // no theme, font, size or spacing of its own
    }

    public function test_choosing_a_theme_and_fonts_changes_the_whole_admin_for_that_person_only(): void
    {
        Livewire::actingAs($this->admin)->test(MakeItYours::class)
            ->call('choose', 'theme', 'mist')
            ->call('choose', 'font', 'classic')
            ->assertRedirect(route('admin.account.yours'));

        $this->assertSame(['theme' => 'mist', 'font' => 'classic'], $this->admin->fresh()->preferences['yours']);
        $this->actingAs($this->admin->fresh())->get(route('admin.contents'))->assertOk()
            ->assertSee('theme-mist', false)->assertSee('font-classic', false)
            ->assertSee('fonts.googleapis.com/css2?family=Lora', false)
            ->assertSee('<meta name="theme-color" content="#1B2029">', false);

        $other = User::factory()->create(['role' => 'editor']);
        $this->actingAs($other)->get(route('admin.contents'))->assertOk()->assertDontSee('theme-mist', false);
    }

    public function test_only_known_choices_are_kept(): void
    {
        $this->assertSame(YourBook::DEFAULTS, YourBook::clean(['layout' => 'magazine', 'theme' => '<b>neon</b>', 'font' => 'comic', 'size' => 'xxl', 'density' => 7]));

        Livewire::actingAs($this->admin)->test(MakeItYours::class)->call('choose', 'password', 'x')->assertStatus(422);
        Livewire::actingAs($this->admin)->test(MakeItYours::class)->call('choose', 'theme', 'neon');
        $this->assertArrayNotHasKey('yours', (array) $this->admin->fresh()->preferences); // nothing differs from Booklet's own
    }

    public function test_the_dashboard_layout_has_a_sidebar_with_your_own_menu_at_its_foot(): void
    {
        User::factory()->create(['role' => 'editor', 'name' => 'Hana Rahman', 'last_seen_at' => now()]);
        YourBook::save($this->admin, ['layout' => 'dashboard']);

        $page = $this->actingAs($this->admin->fresh())->get(route('admin.contents'))->assertOk();

        $page->assertSee('layout-dashboard', false)
            ->assertSee('class="sidebar"', false)
            ->assertDontSee('class="flags"', false)
            ->assertDontSee('class="thumbs"', false)
            ->assertSee('Hana is here too')
            ->assertSee(route('admin.account.yours'), false)
            ->assertSee(route('admin.account.menu'), false)
            ->assertSee(route('admin.night'), false)
            ->assertSee('Sign out');
    }

    public function test_night_reading_can_follow_the_device(): void
    {
        Livewire::actingAs($this->admin)->test(MakeItYours::class)->call('night', 'device');
        $this->actingAs($this->admin->fresh())->get(route('admin.contents'))->assertSee('data-night-auto="on"', false);

        Livewire::actingAs($this->admin->fresh())->test(MakeItYours::class)->call('night', 'on');
        $fresh = $this->admin->fresh();
        $this->assertTrue($fresh->preferences['night']);
        $this->assertFalse(YourBook::of($fresh)['night_auto']);
    }

    public function test_the_night_switch_works_from_anywhere(): void
    {
        $this->actingAs($this->admin)->from(route('admin.media'))->post(route('admin.night'))->assertRedirect(route('admin.media'));
        $this->assertTrue($this->admin->fresh()->preferences['night']);
    }

    public function test_putting_back_booklets_own_look(): void
    {
        YourBook::save($this->admin, ['layout' => 'dashboard', 'theme' => 'rose', 'turn' => false]);

        Livewire::actingAs($this->admin->fresh())->test(MakeItYours::class)->call('putBack');

        $this->assertSame(YourBook::DEFAULTS, YourBook::of($this->admin->fresh()));
    }

    public function test_the_make_it_yours_page_shows_every_choice(): void
    {
        $this->actingAs($this->admin)->get(route('admin.account.yours'))->assertOk()
            ->assertSee('Make it yours')->assertSee('Dashboard')->assertSee('Linen')->assertSee('Easy reading')->assertSee('With my device')->assertSee('Pages turn');
    }

    public function test_make_it_yours_and_your_menu_can_be_found_from_the_book_layout(): void
    {
        $this->actingAs($this->admin)->get(route('admin.account'))->assertOk()
            ->assertSee('class="yours-links"', false)
            ->assertSee(route('admin.account.yours'), false)
            ->assertSee(route('admin.account.menu'), false);

        // The gear's note and the phone's Index list them on every page.
        $page = $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($page, 'href="'.route('admin.account.yours').'"'));
        $this->assertGreaterThanOrEqual(2, substr_count($page, 'href="'.route('admin.account.menu').'"'));

        $this->assertContains('make_it_yours', array_keys(\App\Stacy\Tools::PLACES));
    }

    // Your menu ----------------------------------------------------------------

    public function test_moving_a_tool_onto_the_stickers_and_hiding_another(): void
    {
        Livewire::actingAs($this->admin)->test(YourMenu::class)
            ->call('place', 'calendar', 'sticker')
            ->call('place', 'visits', 'hidden');

        $page = $this->actingAs($this->admin->fresh())->get(route('admin.contents'))->assertOk();
        $this->assertMatchesRegularExpression('/class="flag flag--sky" href="'.preg_quote(route('admin.calendar'), '/').'"/', $page->getContent());
        $this->assertNotContains('visits', array_column(AdminNav::menuIn('thumb'), 'key'));
    }

    public function test_renaming_and_recolouring_are_kept_and_an_empty_name_puts_the_usual_one_back(): void
    {
        $menu = Livewire::actingAs($this->admin)->test(YourMenu::class)
            ->set('names.posts', 'Stories')
            ->call('recolour', 'posts');

        $saved = AdminNav::savedMenu($this->admin->fresh());
        $this->assertSame('Stories', $saved['items']['posts']['label']);
        $this->assertSame('mint', $saved['items']['posts']['tone']); // sun, then mint

        $this->actingAs($this->admin->fresh())->get(route('admin.contents'))->assertSee('>Stories</a>', false);

        $menu->set('names.posts', '   ');
        $this->assertArrayNotHasKey('label', AdminNav::savedMenu($this->admin->fresh())['items']['posts'] ?? []);
        $menu->assertSet('names.posts', 'Posts');
    }

    public function test_moving_up_and_down_and_choosing_the_phone_bar(): void
    {
        Livewire::actingAs($this->admin)->test(YourMenu::class)
            ->call('move', 'media', -1)
            ->call('dockPick', 1, 'comments');

        $this->actingAs($this->admin->fresh())->get(route('admin.contents'));
        $keys = array_column(AdminNav::menuIn('sticker'), 'key');
        $this->assertSame(['contents', 'posts', 'media', 'pages'], array_slice($keys, 0, 4));
        $this->assertSame(['contents', 'comments'], array_column(AdminNav::dock(), 'key'));
    }

    public function test_dragging_rows_into_a_new_order(): void
    {
        Livewire::actingAs($this->admin)->test(YourMenu::class)
            ->assertSee('menu-edit__grip', false)
            ->call('reorder', ['media', 'contents', '../etc', 'media', 7]);

        $this->actingAs($this->admin->fresh())->get(route('admin.contents'));
        $keys = array_column(AdminNav::menu(), 'key');
        $this->assertSame(['media', 'contents'], array_slice($keys, 0, 2));
        $this->assertContains('posts', $keys); // what wasn't sent keeps its place after
    }

    public function test_a_writer_only_arranges_what_they_may_open(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);

        Livewire::actingAs($writer)->test(YourMenu::class)
            ->assertDontSee('id="mi-name-inbox"', false)
            ->call('place', 'inbox', 'sticker')->assertStatus(404);
    }

    public function test_a_saved_menu_is_cleaned(): void
    {
        $clean = AdminNav::cleanMenu([
            'order' => ['posts', '../etc', 7, 'posts'],
            'items' => ['posts' => ['label' => '<script>x</script>Stories', 'tone' => 'neon', 'where' => 'roof'], 'Bad Key' => ['where' => 'thumb']],
            'dock' => ['a', 'b', 'c'],
        ]);

        $this->assertSame(['posts'], $clean['order']);
        $this->assertSame(['posts' => ['label' => 'xStories']], $clean['items']);
        $this->assertSame(['a', 'b'], $clean['dock']);
    }

    public function test_putting_back_the_usual_menu(): void
    {
        Livewire::actingAs($this->admin)->test(YourMenu::class)->call('place', 'posts', 'hidden')->call('putBack');

        $this->assertArrayNotHasKey('menu', (array) $this->admin->fresh()->preferences);
    }
}
