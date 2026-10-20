<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Entries;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The entry shown on the right of a chapter is the one its buttons change,
 * even before anything has been picked from the list.
 */
class EntriesDefaultChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_setting_a_template_works_without_picking_first(): void
    {
        $first = Booklet::entries()->find(Booklet::trees()->collection('pages')->entryIds()[0]);

        Livewire::test(Entries::class, ['collection' => 'pages'])
            ->call('setTemplate', 'full_width')
            ->assertOk();

        Booklet::flush();
        $this->assertSame('full_width', Booklet::entries()->find($first->id())->get('template'));
    }

    public function test_the_menu_switch_works_without_picking_first(): void
    {
        Livewire::test(Entries::class, ['collection' => 'pages'])
            ->call('toggleMenu')
            ->assertOk();
    }
}
