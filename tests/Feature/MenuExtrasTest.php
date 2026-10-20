<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Booklet\Support\MenuOutline;
use App\Booklet\Support\Menus;
use App\Booklet\Support\MenuWords;
use App\Livewire\Admin\Menus as MenusScreen;
use App\Models\User;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use App\Support\Health;
use App\Support\MenuHealth;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The menus' extras: what's wrong with a menu, arranging one in plain words
 * (in the editor and through Stacy), and seasonal versions.
 */
class MenuExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur']);
    }

    protected function rows(array $labels): array
    {
        return MenuOutline::normalize(array_map(fn ($l) => [
            'depth' => strlen($l) - strlen(ltrim($l, '-')),
            'url' => 'https://example.com/'.strtolower(str_replace(' ', '-', ltrim($l, '-'))),
            'title' => ltrim($l, '-'),
        ], $labels));
    }

    protected function shape(array $rows): array
    {
        return array_map(fn ($r) => str_repeat('-', (int) $r['depth']).MenuHealth::label($r), $rows);
    }

    protected function words(array $labels, string $words): array
    {
        return MenuWords::apply($this->rows($labels), $words, fn ($r) => MenuHealth::label($r), fn ($t) => strcasecmp($t, 'Our team') === 0 ? 'team-id' : null);
    }

    // Health -----------------------------------------------------------------

    public function test_it_finds_what_readers_would_trip_over(): void
    {
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $draft = Booklet::entries()->make(['collection' => 'pages', 'slug' => 'soon', 'published' => false], ['title' => 'Coming soon'])->save();

        $rows = MenuOutline::normalize([
            ['depth' => 0, 'entry' => $about->id()],
            ['depth' => 0, 'entry' => 'gone-for-good'],
            ['depth' => 0, 'entry' => $draft->id()],
            ['depth' => 0, 'url' => '/nowhere-at-all', 'title' => 'Nowhere'],
            ['depth' => 0, 'url' => '/blog', 'title' => 'Blog'],
            ['depth' => 0, 'url' => '/search', 'title' => 'Search'],
            ['depth' => 0, 'url' => 'http://old.example.com', 'title' => 'Old shop'],
            ['depth' => 0, 'entry' => $about->id(), 'title' => 'About again'],
            ['depth' => 0, 'url' => 'https://shop.example.com', 'title' => 'Shop', 'mega' => true],
        ]);
        $found = MenuHealth::check($rows, 'header');

        $this->assertArrayNotHasKey(0, $found['rows'], 'a published page is fine');
        $this->assertStringContainsString('deleted', $found['rows'][1][0]['text']);
        $this->assertStringContainsString('draft', $found['rows'][2][0]['text']);
        $this->assertStringContainsString('Nothing on this site at /nowhere-at-all', $found['rows'][3][0]['text']);
        $this->assertArrayNotHasKey(4, $found['rows'], 'the blog is there');
        $this->assertArrayNotHasKey(5, $found['rows'], 'search is one of the site’s own pages');
        $this->assertSame('note', $found['rows'][6][0]['level']);
        $this->assertStringContainsString('higher up', $found['rows'][7][0]['text']);
        $this->assertStringContainsString('wide panel with nothing in it', $found['rows'][8][0]['text']);
        $this->assertStringContainsString('9 items across the top', $found['menu'][0]['text']);
    }

    public function test_the_editor_marks_rows_and_the_check_up_says_so(): void
    {
        Menus::save('header', [['url' => '/nowhere-at-all', 'title' => 'Nowhere'], ['url' => '/blog', 'title' => 'Blog']]);

        Livewire::actingAs($this->admin)->test(MenusScreen::class)
            ->assertSee('1 thing readers would notice')
            ->assertSee('nav-badge--warn', false)
            ->call('save')
            ->assertDispatched('stamp', text: 'Menu saved. The site shows it now. (1 thing to look at, marked in the list.)');

        $check = collect(Health::run())->firstWhere('key', 'site_menus');
        $this->assertSame('warn', $check['state']);
        $this->assertStringContainsString('Nowhere', $check['text']);
    }

    // In plain words --------------------------------------------------------

    public function test_plain_words_arrange_a_menu(): void
    {
        $menu = ['About', 'Journal', '-Latest', '-By topic', 'Prints', 'Shop', 'Contact', 'Prints and posters'];

        $this->assertSame(['Shop', '-Prints', 'About', 'Journal', '-Latest', '-By topic', 'Contact', 'Prints and posters'],
            $this->shape($this->words($menu, 'put Shop first and tuck Prints under it')['rows']));
        $this->assertSame(['About', 'Contact', 'Notes', '-Latest', '-By topic', 'Prints', 'Shop', 'Prints and posters'],
            $this->shape($this->words($menu, 'move Contact after About, then rename Journal to Notes')['rows']));
        $this->assertSame(['About', 'Journal', '-Latest', '-By topic', 'Prints', 'Shop', 'Contact'],
            $this->shape($this->words($menu, 'remove Prints and posters')['rows']), 'a name with “and” in it stays whole');
        $this->assertSame(['About', 'Journal', '-Latest', 'By topic', 'Prints', 'Shop', 'Contact', 'Prints and posters'],
            $this->shape($this->words($menu, 'bring By topic up a level')['rows']));
        $this->assertSame(['Contact', 'About', 'Journal', '-Latest', '-By topic', 'Prints', 'Shop', 'Prints and posters'],
            $this->shape($this->words($menu, 'put Contact at the top')['rows']));

        $added = $this->words($menu, 'add the Our team page under About, add Sale linking to https://shop.example.com/sale.html');
        $this->assertSame('team-id', $added['rows'][1]['entry']);
        $this->assertSame(1, $added['rows'][1]['depth']);
        $this->assertSame('https://shop.example.com/sale.html', end($added['rows'])['url'], 'an address is kept whole');

        $odd = $this->words($menu, 'make it purple');
        $this->assertSame([], $odd['done']);
        $this->assertStringContainsString('I didn’t follow', $odd['problems'][0]);
        $this->assertStringContainsString('no “Nonsense”', $this->words($menu, 'remove Nonsense')['problems'][0]);
    }

    public function test_the_editor_takes_words_and_undo_takes_them_back(): void
    {
        Menus::save('header', [['url' => '/blog', 'title' => 'Blog'], ['url' => '/search', 'title' => 'Search'], ['url' => '/shop', 'title' => 'Shop']]);

        $screen = Livewire::actingAs($this->admin)->test(MenusScreen::class)
            ->set('words', 'put Shop first and tuck Search under it')
            ->call('arrange')
            ->assertHasNoErrors()
            ->assertSet('words', '')
            ->assertSet('dirty', true);
        $this->assertSame(['Shop', '-Search', 'Blog'], $this->shape($screen->get('rows')));

        $screen->call('undo');
        $this->assertSame(['Blog', 'Search', 'Shop'], $this->shape($screen->get('rows')));

        $screen->set('words', 'remove Nonsense')->call('arrange')->assertHasErrors('words');
    }

    public function test_stacy_arranges_a_menu_after_a_yes(): void
    {
        Menus::save('header', [['url' => '/blog', 'title' => 'Blog'], ['url' => '/prints', 'title' => 'Prints'], ['url' => '/shop', 'title' => 'Shop']]);
        $tools = new Tools($this->admin);

        $read = json_decode($tools->run(new ToolCall('t1', 'read_menu', [])), true);
        $this->assertSame(['- Blog (/blog)', '- Prints (/prints)', '- Shop (/shop)'], $read['items']);
        $this->assertNotEmpty($read['problems'], '/prints and /shop have nothing at them');

        $proposed = json_decode($tools->run(new ToolCall('t2', 'propose_menu', ['instructions' => 'put Shop first and tuck Prints under it'])), true);
        $this->assertSame('waiting_for_confirmation', $proposed['status']);
        $this->assertSame(['Blog', 'Prints', 'Shop'], array_column(Menus::items('header'), 'title'), 'nothing changes before the yes');

        $done = Tools::confirm($tools->pending, $this->admin);
        $this->assertStringContainsString('Done.', $done['text']);
        $this->assertSame(['Shop', 'Blog'], array_column(Menus::items('header'), 'title'));
        $this->assertSame('Prints', Menus::items('header')[0]['children'][0]['title']);

        // Changed since she offered: she doesn't overwrite it.
        $tools2 = new Tools($this->admin);
        $tools2->run(new ToolCall('t3', 'propose_menu', ['instructions' => 'remove Blog']));
        Menus::save('header', [['url' => '/new', 'title' => 'Changed by hand']]);
        $this->assertStringContainsString('changed that menu since', Tools::confirm($tools2->pending, $this->admin)['text']);

        $writer = User::factory()->create(['role' => 'writer']);
        $this->assertArrayHasKey('error', json_decode((new Tools($writer))->run(new ToolCall('t4', 'propose_menu', ['instructions' => 'remove Blog'])), true));
    }

    // Seasons ------------------------------------------------------------------

    public function test_a_seasonal_menu_takes_over_on_its_dates(): void
    {
        config(['app.timezone' => 'Asia/Brunei']);
        Menus::save('header', [['url' => '/blog', 'title' => 'Everyday']]);

        $screen = Livewire::actingAs($this->admin)->test(MenusScreen::class)
            ->set('seasonName', 'Raya menu')->set('seasonFrom', '2027-03-08')->set('seasonUntil', '2027-04-10')
            ->call('addSeason')->assertHasNoErrors();
        $raya = Menus::seasons('header')[0]['handle'];
        $screen->assertSet('menu', $raya);
        $this->assertSame(['Everyday'], array_column(Menus::items($raya), 'title'), 'starts as a copy');
        $this->assertArrayNotHasKey($raya, Menus::all(), 'not one of the site’s own menus');

        $screen->set('words', 'rename Everyday to Selamat Hari Raya')->call('arrange')->call('save');

        // In Brunei it's already 8 March at 4pm UTC on the 7th.
        $this->travelTo(Carbon::parse('2027-03-07 15:00', 'UTC'));
        $this->assertSame('Everyday', app(Site::class)->menu('header')[0]['label']);
        $this->travelTo(Carbon::parse('2027-03-07 16:30', 'UTC'));
        app()->forgetInstance(Site::class);
        $this->assertSame('Selamat Hari Raya', app(Site::class)->menu('header')[0]['label']);
        $this->assertSame('on', Menus::seasonState(Menus::season($raya)));

        // The day after its last day, the usual one is back; and pages are made fresh right then.
        $this->travelTo(Carbon::parse('2027-04-10 16:30', 'UTC'));
        app()->forgetInstance(Site::class);
        $this->assertSame('Everyday', app(Site::class)->menu('header')[0]['label']);
        $this->travelTo(Carbon::parse('2027-03-01 00:00', 'UTC'));
        $this->assertSame('2027-03-07 16:00', Menus::nextSeasonChange()->utc()->format('Y-m-d H:i'));
        $this->travelBack();

        // Thrown away: gone, and the editor goes back to the usual menu.
        Livewire::actingAs($this->admin)->test(MenusScreen::class, ['menu' => $raya])
            ->assertSee('Stands in for the header menu')
            ->call('deleteSeason')->assertSet('menu', 'header');
        $this->assertSame([], Menus::seasons('header'));
    }

    public function test_previewing_a_seasonal_menu_shows_it_in_the_header(): void
    {
        Menus::save('header', [['url' => '/blog', 'title' => 'Everyday']]);
        $raya = Menus::addSeason('header', 'Raya menu', '2030-01-01', '2030-01-31');

        Livewire::actingAs($this->admin)->test(MenusScreen::class, ['menu' => $raya])
            ->set('words', 'rename Everyday to Raya treats')->call('arrange');

        $this->actingAs($this->admin)->get('/?menu_preview=1')->assertSee('Raya treats');
        $this->actingAs($this->admin)->get('/')->assertDontSee('Raya treats')->assertSee('Everyday');
        $this->assertNotNull(Cache::get(Site::menuPreviewKey($this->admin->id)));
    }

    public function test_a_deleted_page_leaves_seasonal_menus_too(): void
    {
        $about = Booklet::entries()->findBySlug('pages', 'about');
        Menus::save('header', [['entry' => $about->id()]]);
        $raya = Menus::addSeason('header', 'Raya', '2030-01-01', '2030-01-31');

        Menus::forgetEntry($about->id());

        $this->assertFalse(Menus::has($raya, $about->id()));
    }
}
