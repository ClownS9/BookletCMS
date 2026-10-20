<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\SearchPalette;
use App\Models\User;
use App\Support\SearchIndex;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The search window: on every admin page, finds things, filters, and
 * understands status: and in:.
 */
class SearchPaletteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Nur Aziz']));
    }

    public function test_it_is_on_every_admin_page(): void
    {
        $this->get(route('admin.contents'))->assertSeeLivewire(SearchPalette::class);
        $this->get(route('admin.entries', 'pages'))->assertSeeLivewire(SearchPalette::class);
    }

    public function test_before_typing_it_offers_recent_work_and_actions(): void
    {
        Livewire::test(SearchPalette::class)
            ->assertSee('Recently edited')
            ->assertSee('Hello from Booklet')
            ->assertSee('Quick actions')
            ->assertSee('New post');
    }

    public function test_it_finds_and_marks_matches_across_the_book(): void
    {
        Livewire::test(SearchPalette::class)
            ->set('q', 'hello')
            ->assertSee('Ask Stacy')
            ->assertSeeHtml('<mark>Hello</mark> from Booklet')
            ->set('q', 'nur')
            ->assertSee('Nur Aziz');
    }

    public function test_filters_and_helpers(): void
    {
        Livewire::test(SearchPalette::class)
            ->set('q', 'about')
            ->call('setScope', 'media')
            ->assertSet('scope', 'media')
            ->assertDontSeeHtml('<mark>About</mark>')
            ->set('q', 'in:pages about')
            ->assertSet('scope', 'pages')
            ->assertSeeHtml('<mark>About</mark>');

        $index = new SearchIndex('status:draft hello');
        $this->assertSame('draft', $index->status);
        $this->assertSame('hello', $index->query);
    }

    public function test_the_old_search_page_is_gone_and_stacy_opens_the_window(): void
    {
        $this->get('/admin/search')->assertNotFound();

        $tools = new \App\Stacy\Tools(auth()->user());
        $tools->goTo('search');
        $this->assertStringEndsWith('#search', $tools->navigate['href']);
    }

    public function test_marking_is_safe(): void
    {
        $this->assertSame('&lt;b&gt;<mark>bold</mark>&lt;/b&gt;', (string) SearchIndex::mark('<b>bold</b>', 'bold'));
    }
}
