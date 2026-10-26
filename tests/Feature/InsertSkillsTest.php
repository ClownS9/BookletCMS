<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Inserts\InsertManager;
use App\Livewire\Admin\InsertPage;
use App\Livewire\Admin\Inserts as Shelf;
use App\Livewire\Admin\StacyBehaviour;
use App\Models\User;
use App\Stacy\StacyConfig;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Inserts teaching Stacy: look-ups, and changes that wait for "Yes".
 */
class InsertSkillsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ?string $dir = null;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        config(['booklet.inserts_path' => base_path('tests/Fixtures/inserts')]);
        $this->app->forgetInstance(InsertManager::class);
    }

    protected function tearDown(): void
    {
        if ($this->dir) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    protected function switchOn(string $key): void
    {
        app(InsertManager::class)->enable($key);
        app(InsertManager::class)->reboot();
    }

    protected function ask(Tools $tools, string $name, array $args = []): array
    {
        return json_decode($tools->run(new ToolCall('t1', $name, $args)), true);
    }

    public function test_stacy_adds_an_event_after_yes_and_lists_what_is_coming(): void
    {
        $this->switchOn('events');
        $tools = new Tools($this->admin);
        $names = array_column($tools->definitions(), 'name');
        $this->assertContains('events__upcoming', $names);
        $this->assertContains('events__add', $names);

        // Something missing or wrong is passed back for her to ask about.
        $this->assertSame('It needs date.', $this->ask($tools, 'events__add', ['title' => 'Bake sale'])['error']);
        $this->assertStringContainsString('date', $this->ask($tools, 'events__add', ['title' => 'Bake sale', 'date' => 'next week'])['error']);
        $this->assertNull($tools->pending);

        $date = now()->addDays(5)->format('Y-m-d');
        $result = $this->ask($tools, 'events__add', ['title' => 'Bake sale', 'date' => $date, 'start_time' => '10:00', 'venue' => 'Tamu Kianggeh', 'sneaky' => 'ignored']);
        $this->assertSame('waiting_for_confirmation', $result['status']);
        $this->assertNotNull($tools->pending);
        $this->assertStringContainsString('Bake sale', $tools->pending['summary']);
        $this->assertStringContainsString('at 10:00am, at Tamu Kianggeh', $tools->pending['summary']);
        $this->assertSame('Yes, add it', $tools->pending['button']);
        $this->assertSame(0, app(InsertManager::class)->find('events')->records('events')->count()); // nothing yet

        $done = Tools::confirm($tools->pending, $this->admin);
        $this->assertStringContainsString('is on the events page', $done['text']);
        $event = app(InsertManager::class)->find('events')->records('events')->all()->first();
        $this->assertSame($date.' 10:00', $event->field('starts'));
        $this->assertStringContainsString('?open='.$event->id, $done['link']['href']);
        $this->assertDatabaseHas('activity_log', ['actor' => 'stacy', 'action' => 'used the insert', 'subject_key' => 'events']);

        // The same again is turned away.
        $this->assertStringContainsString('already', $this->ask(new Tools($this->admin), 'events__add', ['title' => 'bake sale', 'date' => $date])['error']);

        $tools = new Tools($this->admin);
        $list = $this->ask($tools, 'events__upcoming');
        $this->assertSame('Bake sale', $list['upcoming'][0]['title']);
        $this->assertSame('Tamu Kianggeh', $list['upcoming'][0]['venue']);
        $this->assertArrayNotHasKey('links', $list);
        $this->assertSame('Bake sale', $tools->links[0]['label']);
        $this->assertSame('var(--coral)', $tools->links[0]['tone']);

        // Its link opens that event on the Events page.
        $this->actingAs($this->admin);
        Livewire::withQueryParams(['open' => $event->id])->test(InsertPage::class, ['insert' => 'events'])
            ->assertSet('editing', $event->id)->assertSet('form.title', 'Bake sale');
    }

    public function test_who_gets_which_skill_and_the_switch_for_changes(): void
    {
        $this->switchOn('events');
        $writer = User::factory()->create(['role' => 'writer']);
        $names = array_column((new Tools($writer))->definitions(), 'name');
        $this->assertContains('events__upcoming', $names);
        $this->assertNotContains('events__add', $names); // changes are for editors and admins
        $this->assertArrayHasKey('error', $this->ask(new Tools($writer), 'events__add', ['title' => 'x', 'date' => '2030-01-01']));

        $tools = new Tools($this->admin);
        $this->ask($tools, 'events__add', ['title' => 'Kite day', 'date' => now()->addDay()->format('Y-m-d')]);
        $pending = $tools->pending;

        StacyConfig::save(['can_inserts' => false]);
        $names = array_column((new Tools($this->admin))->definitions(), 'name');
        $this->assertContains('events__upcoming', $names); // looking up is always fine
        $this->assertNotContains('events__add', $names);
        $this->assertStringContainsString('switched off', Tools::confirm($pending, $this->admin)['text']);

        // Switching the insert off takes its skills away too.
        StacyConfig::save(['can_inserts' => true]);
        app(InsertManager::class)->disable('events');
        app(InsertManager::class)->forget();
        $this->assertStringContainsString('isn’t switched on', Tools::confirm($pending, $this->admin)['text']);
        $this->assertNotContains('events__upcoming', array_column((new Tools($this->admin))->definitions(), 'name'));
        $this->assertSame(0, app(InsertManager::class)->find('events')->records('events')->count());
    }

    public function test_a_misbehaving_skill_never_takes_stacy_down(): void
    {
        $this->dir = storage_path('framework/testing/inserts-'.uniqid());
        File::ensureDirectoryExists($this->dir.'/oddity');
        File::put($this->dir.'/oddity/insert.php', <<<'PHP'
        <?php
        return [
            'name' => 'Oddity',
            'stacy' => [
                'boom' => ['about' => 'Breaks.', 'look' => fn () => throw new RuntimeException('Kaboom')],
                'sneaky' => ['about' => 'Links.', 'look' => fn () => ['ok' => true, 'links' => [
                    ['label' => 'Bad', 'href' => 'javascript:alert(1)'], ['label' => 'Far', 'href' => '//evil.example'], ['label' => 'Good', 'href' => '/admin'],
                ]]],
                'half' => ['about' => 'A change with no do.', 'propose' => fn () => []],
                'Bad Name' => ['about' => 'x', 'look' => fn () => []],
                'vague' => ['about' => 'Offers nothing.', 'propose' => fn () => ['data' => []], 'do' => fn () => 'x'],
            ],
        ];
        PHP);
        config(['booklet.inserts_path' => $this->dir]);
        $this->app->forgetInstance(InsertManager::class);
        $this->switchOn('oddity');

        $tools = new Tools($this->admin);
        $names = array_column($tools->definitions(), 'name');
        $this->assertContains('oddity__boom', $names);
        $this->assertNotContains('oddity__half', $names);
        $this->assertSame([], array_filter($names, fn ($n) => str_contains($n, 'Bad')));

        $this->assertStringContainsString('had a problem', $this->ask($tools, 'oddity__boom')['error']);
        $this->assertStringContainsString('Kaboom', app(InsertManager::class)->find('oddity')->error());

        $this->assertTrue($this->ask($tools, 'oddity__sneaky')['ok']);
        $this->assertSame(['/admin'], array_column($tools->links, 'href'));

        $this->assertStringContainsString('didn’t say', $this->ask($tools, 'oddity__vague')['error']);
        $this->assertNull($tools->pending);
    }

    public function test_admins_see_what_inserts_teach_her(): void
    {
        $this->switchOn('events');
        $this->switchOn('gallery');
        $this->actingAs($this->admin);

        // Gallery: only pictures that are in Media.
        $this->assertStringContainsString('None of those are pictures', $this->ask(new Tools($this->admin), 'gallery__make_album', ['name' => 'Market', 'pictures' => ['nope.jpg']])['error']);

        Livewire::test(StacyBehaviour::class)
            ->assertSee('Use inserts')
            ->assertSee('What inserts teach her')
            ->assertSee('Makes an album from pictures in Media')
            ->call('toggle', 'canInserts')->call('save');
        $this->assertFalse((bool) StacyConfig::get('can_inserts'));

        Livewire::test(Shelf::class)->call('pick', 'events')
            ->assertSee('It teaches Stacy')
            ->assertSee('Adds an event');
    }
}
