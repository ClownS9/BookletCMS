<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Inserts\InsertManager;
use App\Livewire\Admin\Contents;
use App\Models\User;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cards inserts put on the Contents page.
 */
class InsertCardsTest extends TestCase
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
        config(['booklet.inserts_path' => base_path('inserts')]);
        $this->app->forgetInstance(InsertManager::class);
    }

    protected function tearDown(): void
    {
        if ($this->dir) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    protected function useShelf(string $dir): void
    {
        config(['booklet.inserts_path' => $dir]);
        $this->app->forgetInstance(InsertManager::class);
    }

    public function test_events_this_week_show_on_contents_and_in_the_brief(): void
    {
        $manager = app(InsertManager::class);
        $manager->enable('events');
        $this->actingAs($this->admin);

        Livewire::test(Contents::class)->assertDontSee('Coming up this week'); // nothing to say

        $events = $manager->find('events')->records('events');
        $soon = $events->create(['title' => 'Bake sale', 'starts' => now()->addDays(2)->format('Y-m-d').' 10:00', 'all_day' => false, 'venue' => 'Tamu Kianggeh']);
        $events->create(['title' => 'Kite day', 'starts' => now()->addDays(30)->format('Y-m-d').' 09:00', 'all_day' => false]);

        Livewire::test(Contents::class)
            ->assertSee('Coming up this week · 1')
            ->assertSee('Bake sale')
            ->assertSee('10:00am · Tamu Kianggeh')
            ->assertSee('?open='.$soon->id, false)
            ->assertDontSee('Kite day');

        // Writers can't open the Events page, so they don't get its card.
        $this->actingAs(User::factory()->create(['role' => 'writer']));
        Livewire::test(Contents::class)->assertDontSee('Coming up this week');

        $brief = json_decode((new Tools($this->admin))->run(new ToolCall('t', 'daily_brief', [])), true);
        $this->assertSame('Coming up this week', $brief['from_inserts'][0]['what']);
        $this->assertStringContainsString('Bake sale', $brief['from_inserts'][0]['items'][0]);
    }

    public function test_a_card_that_fails_or_misbehaves_never_spoils_the_page(): void
    {
        $this->dir = storage_path('framework/testing/inserts-'.uniqid());
        File::ensureDirectoryExists($this->dir.'/oddity');
        File::put($this->dir.'/oddity/insert.php', <<<'PHP'
        <?php
        return [
            'name' => 'Oddity',
            'contents' => [
                'boom' => ['title' => 'Boom card', 'show' => fn () => throw new RuntimeException('Card kaboom')],
                'links' => ['title' => 'Link card', 'show' => fn () => ['items' => [
                    ['label' => 'Sneaky', 'href' => 'javascript:alert(1)'], ['label' => 'Fine', 'href' => '/admin/media'], ['label' => ''],
                ], 'link' => ['label' => 'Nope', 'href' => '//evil.example']]],
            ],
        ];
        PHP);
        $this->useShelf($this->dir);
        app(InsertManager::class)->enable('oddity');
        $this->actingAs($this->admin);

        $page = Livewire::test(Contents::class)
            ->assertSee('Link card')
            ->assertDontSee('Boom card')
            ->assertDontSee('javascript:', false)
            ->assertDontSee('evil.example', false)
            ->assertSee('Sneaky')
            ->assertSee('/admin/media', false);
        $this->assertStringContainsString('Card kaboom', app(InsertManager::class)->find('oddity')->error());
        $page->assertSee('At a glance'); // the rest of the page is all there
    }

    public function test_a_newly_made_insert_works_out_of_the_box(): void
    {
        $this->dir = storage_path('framework/testing/inserts-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        $this->useShelf($this->dir);

        $this->artisan('booklet:insert', ['name' => 'reading-list'])->assertSuccessful();
        $manager = app(InsertManager::class);
        $manager->enable('reading-list');
        $manager->reboot();
        $insert = $manager->find('reading-list');
        $this->assertTrue($insert->active());
        $this->assertSame([], $manager->cards($this->admin)); // empty list: nothing to say

        $insert->records('items')->create(['title' => 'Salt Fat Acid Heat']);
        $this->actingAs($this->admin);
        Livewire::test(Contents::class)->assertSee('On the Reading List list · 1')->assertSee('Salt Fat Acid Heat');

        $tools = new Tools($this->admin);
        $this->assertSame(['Salt Fat Acid Heat'], json_decode($tools->run(new ToolCall('t', 'reading-list__list', [])), true)['items']);
        json_decode($tools->run(new ToolCall('t', 'reading-list__add', ['title' => 'The Flavour Thesaurus'])), true);
        Tools::confirm($tools->pending, $this->admin);
        $this->assertSame(2, $insert->records('items')->count());
    }
}
