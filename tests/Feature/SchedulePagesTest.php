<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Livewire\Admin\Entries;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pages can be scheduled like posts: they wait out of sight until their time,
 * then go out by themselves. A page keeps a date only while it's waiting.
 */
class SchedulePagesTest extends TestCase
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

    public function test_a_page_can_be_scheduled_and_goes_out_by_itself(): void
    {
        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages'])
            ->assertSee('Scheduled')
            ->set('values.title', 'Raya opening hours')
            ->call('setStatus', 'scheduled');

        // Tomorrow morning is suggested, and the date field shows.
        $this->assertTrue(\Illuminate\Support\Carbon::parse($editor->get('date'))->isFuture());
        $editor->assertSee('entry-date', false);

        $editor->set('date', now()->addDays(2)->format('Y-m-d\TH:i'))->call('save')->assertHasNoErrors();

        $page = Booklet::entries()->findBySlug('pages', 'raya-opening-hours');
        $this->assertSame('scheduled', $page->status());
        $this->assertFalse(app(Site::class)->published()->contains(fn ($e) => $e->id() === $page->id()));

        $this->travel(3)->days();
        Booklet::entries()->forget();
        $this->assertSame('published', Booklet::entries()->find($page->id())->status());
    }

    public function test_a_page_cannot_be_scheduled_for_a_time_already_gone(): void
    {
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages'])
            ->set('values.title', 'Too late')
            ->call('setStatus', 'scheduled')
            ->set('date', now()->subHour()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasErrors(['date' => 'after']);
    }

    public function test_publishing_or_drafting_a_page_drops_its_date(): void
    {
        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages'])
            ->set('values.title', 'Opening soon')
            ->call('setStatus', 'scheduled')
            ->call('save');
        $page = Booklet::entries()->findBySlug('pages', 'opening-soon');

        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $page->id()])
            ->call('setStatus', 'published')->call('save')->assertHasNoErrors();
        $page = Booklet::entries()->find($page->id());
        $this->assertSame('published', $page->status());
        $this->assertNull($page->date());
    }

    public function test_the_pages_list_gets_a_scheduled_tab_and_publish_now_works(): void
    {
        Livewire::actingAs($this->admin)->test(Entries::class, ['collection' => 'pages'])->assertDontSee('Scheduled');

        $page = Booklet::entries()->make(['collection' => 'pages', 'published' => true, 'date' => now()->addDay()->toDateTimeString()], ['title' => 'Later'])->save();

        Livewire::actingAs($this->admin)->test(Entries::class, ['collection' => 'pages'])
            ->assertSee('Scheduled')
            ->set('ticked', [$page->id()])
            ->call('publishTicked');

        $page = Booklet::entries()->find($page->id());
        $this->assertSame('published', $page->status());
        $this->assertNull($page->date());
    }
}
