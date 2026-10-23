<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\CoverDesigner;
use App\Models\User;
use App\Support\Look;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The cover: the site's look from presets and choices, previewed before
 * it's used, and worn by every page.
 */
class CoverDesignerTest extends TestCase
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

    public function test_the_look_is_always_whole_and_sensible(): void
    {
        $look = Look::clean(['title_font' => 'comic-sans', 'accent' => 'red', 'home' => 'list']);

        $this->assertSame('newsreader', $look['title_font'], 'unknown fonts fall back');
        $this->assertSame('#F4876A', $look['accent']);
        $this->assertSame('list', $look['home']);
        $this->assertSame('#FFFFFF', Look::onAccent('#C0392B'), 'white text on a dark red');
        $this->assertSame('#1E1C18', Look::onAccent('#F3C24F'), 'dark text on a sunny yellow');
    }

    public function test_designing_previews_first_then_the_site_wears_it(): void
    {
        $screen = Livewire::actingAs($this->admin)->test(CoverDesigner::class)
            ->call('preset', 'broadsheet')
            ->assertSet('dirty', true)
            ->assertDispatched('look-preview')
            ->set('look.home', 'grid');

        // Not on the site yet; only on the admin's preview.
        $this->get('/')->assertSee('look-header--classic', false);
        $this->actingAs($this->admin)->get('/?look_preview=1')
            ->assertSee('look-header--centered', false)
            ->assertSee('family=Playfair+Display', false)
            ->assertSee('--accent: #C0392B', false)
            ->assertSee('--on-accent: #FFFFFF', false);

        $screen->call('save')->assertSet('dirty', false)->assertDispatched('stamp');
        auth()->logout();

        $home = $this->get('/');
        $home->assertSee('look-header--centered look-home--grid look-corners--sharp look-flat', false)
            ->assertSee("--serif: 'Playfair Display'", false)
            ->assertDontSee('class="feature"', false);
        $this->assertSame('#C0392B', Booklet::globals('site')->get('accent'), 'the accent is where the rest of Booklet looks for it');
    }

    public function test_the_list_home_page_has_no_pictures_but_first_lines(): void
    {
        Look::save(['preset' => 'broadsheet']);

        $this->get('/')->assertSee('look-home--list', false)->assertSee('card__excerpt', false);
    }

    public function test_only_admins_design_and_only_they_see_a_preview(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.settings.look'))->assertForbidden();

        \Illuminate\Support\Facades\Cache::put(Look::previewKey($editor->id), ['preset' => 'studio']);
        $this->actingAs($editor)->get('/?look_preview=1')->assertDontSee('look-corners--sharp', false);
    }

    public function test_undo_goes_back_to_the_one_in_use(): void
    {
        Livewire::actingAs($this->admin)->test(CoverDesigner::class)
            ->call('preset', 'studio')
            ->call('undo')
            ->assertSet('dirty', false)
            ->assertSet('look.preset', 'notebook');
    }
}
