<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Console\BookletCli;
use App\Console\Commands\BookletPackage;
use App\Livewire\Admin\Upkeep as UpkeepPage;
use App\Models\User;
use App\Support\Upkeep;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * php booklet, and Settings › Upkeep: clearing what's safe to clear, tidying
 * the writing (seen first), readers away for a while, and starting again.
 */
class UpkeepTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah']);
        @unlink(storage_path(Upkeep::AWAY_FILE));
    }

    protected function tearDown(): void
    {
        @unlink(storage_path(Upkeep::AWAY_FILE));
        parent::tearDown();
    }

    protected function messy(string $content, string $title = 'Kuih  recipes'): \App\Booklet\Data\Entry
    {
        $entry = Booklet::entries()->all()->first(fn ($e) => $e->collectionHandle() === 'posts');
        $entry->set('title', $title)->set('content', $content)->save();
        Booklet::entries()->forget();

        return Booklet::entries()->find($entry->id());
    }

    // The command line ------------------------------------------------------------

    public function test_booklet_commands_are_typed_without_their_prefix(): void
    {
        $known = ['booklet:backup', 'booklet:cache:clear', 'migrate'];

        $this->assertSame('booklet:backup', BookletCli::name('backup', $known));
        $this->assertSame('booklet:cache:clear', BookletCli::name('cache:clear', $known));
        $this->assertSame('migrate', BookletCli::name('migrate', $known));
        $this->assertSame('booklet:backup', BookletCli::name('booklet:backup', $known));
        $this->assertSame('nonsense', BookletCli::name('nonsense', $known));

        $input = BookletCli::input($this->app, ['booklet', 'tidy', '--apply']);
        $this->assertSame('booklet:tidy', $input->getFirstArgument());
        $this->assertSame('booklet:list', BookletCli::input($this->app, ['booklet'])->getFirstArgument());
        $this->assertSame('list', BookletCli::input($this->app, ['booklet', 'list', '--all'])->getFirstArgument());
        $this->assertStringStartsWith('Booklet ', BookletCli::version($this->app, ['booklet', '--version']));
        $this->assertNull(BookletCli::version($this->app, ['booklet', 'backup']));
    }

    public function test_the_list_groups_booklets_commands(): void
    {
        $this->artisan('booklet:list')
            ->expectsOutputToContain('Upkeep')
            ->expectsOutputToContain('cache:clear')
            ->expectsOutputToContain('pictures:rebuild')
            ->expectsOutputToContain('tidy')
            ->assertSuccessful();
    }

    public function test_the_booklet_file_is_packed_and_kept_by_updates(): void
    {
        $this->assertFileExists(base_path('booklet'));
        $this->assertContains('booklet', BookletPackage::INCLUDE);
        $this->assertContains('booklet', \App\Support\Updates::OWNED);
    }

    // Clearing ------------------------------------------------------------------

    public function test_clearing_the_caches_keeps_booklets_own_notes(): void
    {
        Cache::forever('booklet.webhooks.due', ['x']);
        Cache::forever('booklet.catalog.themes', ['old']);

        $done = Upkeep::clearCaches();

        $this->assertNotEmpty($done);
        $this->assertSame(['x'], Cache::get('booklet.webhooks.due')); // never Cache::flush()
        $this->assertArrayHasKey('clear', Upkeep::last());

        $this->artisan('booklet:cache:clear')->assertSuccessful();
    }

    // Tidying -------------------------------------------------------------------

    public function test_tidying_text_leaves_code_and_line_breaks_alone(): void
    {
        [$text, $rules] = Upkeep::tidyText("Hello   \r\nline one  \nline two\n\n\n\n```\nkeep   \n```\n\u{200B}end");

        $this->assertSame("Hello\nline one  \nline two\n\n```\nkeep   \n```\nend", $text);
        $this->assertEqualsCanonicalizing(['endings', 'spaces', 'blank', 'invisible'], $rules);
    }

    public function test_headings_start_under_the_title_and_go_one_step_at_a_time(): void
    {
        [$text, $rules] = Upkeep::tidyText("# Start\n\ntext\n\n#### Deep\n\n## Next");

        $this->assertSame("## Start\n\ntext\n\n### Deep\n\n### Next", $text);
        $this->assertSame(['headings'], $rules);

        [$tidy, $none] = Upkeep::tidyText("## Fine\n\n### Also fine");
        $this->assertSame("## Fine\n\n### Also fine", $tidy);
        $this->assertSame([], $none);
    }

    public function test_tidying_is_seen_first_then_saved_with_history(): void
    {
        $entry = $this->messy("Words   \n\n\n\nMore");

        $preview = collect(Upkeep::tidyPreview())->firstWhere(fn ($c) => $c['entry']->id() === $entry->id());
        $this->assertNotNull($preview);
        $this->assertContains('title', $preview['rules']);
        $this->assertSame("Words   \n\n\n\nMore", Booklet::entries()->find($entry->id())->get('content')); // nothing saved yet

        $this->artisan('booklet:tidy')->assertSuccessful(); // looking only

        $this->assertGreaterThanOrEqual(1, Upkeep::tidyApply([$entry->id()]));
        Booklet::entries()->forget();
        $fresh = Booklet::entries()->find($entry->id());
        $this->assertSame("Words\n\nMore", $fresh->get('content'));
        $this->assertSame('Kuih recipes', $fresh->get('title'));
        $this->assertNull(collect(Upkeep::tidyPreview())->firstWhere(fn ($c) => $c['entry']->id() === $entry->id()));
    }

    // Readers away --------------------------------------------------------------

    public function test_readers_see_back_soon_while_the_book_keeps_working(): void
    {
        $this->artisan('booklet:down', ['message' => 'Back by six'])->assertSuccessful();

        $this->get('/')->assertStatus(503)->assertSee('Back soon')->assertSee('Back by six')->assertHeader('Retry-After');
        $this->get('/login')->assertOk();
        $this->actingAs($this->admin)->get('/')->assertOk();
        $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()->assertSee('class="away-bar"', false);

        $this->artisan('booklet:up')->assertSuccessful();
        $this->app["auth"]->guard()->forgetUser();
        $this->get('/')->assertOk();
        $this->assertNull(Upkeep::readersAway());
    }

    public function test_readers_come_back_by_themselves_when_the_time_is_up(): void
    {
        Upkeep::sendReadersAway('', null, 30);
        $this->assertNotNull(Upkeep::readersAway());

        $this->travel(31)->minutes();
        $this->assertNull(Upkeep::readersAway());
        $this->get('/')->assertOk();
    }

    // The page ------------------------------------------------------------------

    public function test_only_admins_open_upkeep(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get(route('admin.settings.upkeep'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.settings.upkeep'))->assertOk()
            ->assertSee('Clear the caches')->assertSee('Tidy the writing')->assertSee('Readers away for a while')->assertSee('php booklet');
        $this->actingAs($this->admin)->get(route('admin.settings'))->assertSee(route('admin.settings.upkeep'), false);
    }

    public function test_the_page_clears_tidies_and_sends_readers_away(): void
    {
        $entry = $this->messy("Words   \n\n\n\nMore");

        Livewire::actingAs($this->admin)->test(UpkeepPage::class)
            ->call('clearCaches')
            ->call('lookTidy')
            ->assertSee('Kuih  recipes')
            ->set('tidyPick', [$entry->id()])
            ->call('applyTidy')
            ->set('awayMessage', 'Moving house')
            ->set('awayFor', '45')
            ->call('sendAway')
            ->assertHasNoErrors()
            ->assertSee('Moving house');

        Booklet::entries()->forget();
        $this->assertSame("Words\n\nMore", Booklet::entries()->find($entry->id())->get('content'));
        $this->assertSame('Nur Aisyah', Upkeep::readersAway()['by']);

        Livewire::actingAs($this->admin)->test(UpkeepPage::class)->call('welcomeBack');
        $this->assertNull(Upkeep::readersAway());

        Livewire::actingAs($this->admin)->test(UpkeepPage::class)->set('awayFor', '0')->call('sendAway')->assertHasErrors('awayFor');
    }

    // Starting again ------------------------------------------------------------

    public function test_starting_again_backs_up_first_and_only_with_the_sites_name(): void
    {
        Storage::fake('local');
        $this->messy('Something of mine');

        $this->artisan('booklet:reset')
            ->expectsQuestion('Type the site’s name ('.(Booklet::globals('site')?->get('site_name') ?: 'booklet').') to go ahead', 'wrong')
            ->assertFailed();
        $this->assertTrue(Booklet::entries()->all()->contains(fn ($e) => $e->get('content') === 'Something of mine'));

        $this->artisan('booklet:reset', ['--force' => true])->assertSuccessful();

        Booklet::flush();
        $this->assertFalse(Booklet::entries()->all()->contains(fn ($e) => $e->get('content') === 'Something of mine'));
        $this->assertNotEmpty(Booklet::entries()->all()); // the starter book is back
        $this->assertTrue(\App\Support\Backups::list()->contains(fn ($b) => str_contains($b['name'], 'before-reset')));
    }
}
