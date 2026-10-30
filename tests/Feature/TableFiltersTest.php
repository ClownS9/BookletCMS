<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Entries;
use App\Models\User;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filters on a chapter's table (Dashboard layout): who wrote it, its
 * categories and tags, its date, when it was last edited, and a search that
 * can look through the writing too.
 */
class TableFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $hana;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah']);
        $this->hana = User::factory()->create(['role' => 'writer', 'name' => 'Hana Rahman']);
        YourBook::save($this->admin, ['layout' => 'dashboard']);
        $this->admin = $this->admin->fresh();

        $post = fn (string $slug, string $title, array $data, string $date) => Booklet::entries()->make(
            ['collection' => 'posts', 'slug' => $slug, 'published' => true, 'date' => $date],
            ['title' => $title] + $data
        )->save();
        $post('pandan-chiffon', 'Pandan chiffon', ['author' => $this->admin->id, 'categories' => ['baking'], 'tags' => ['pandan'], 'content' => 'Soft and green.'], now()->subDays(2)->toDateTimeString());
        $post('kuih-lapis', 'Kuih lapis', ['author' => $this->hana->id, 'categories' => ['baking'], 'content' => 'Nine layers of coconut milk.'], now()->subDays(40)->toDateTimeString());
        $post('market-day', 'Market day', ['author' => $this->hana->id, 'content' => 'Pandan leaves were cheap.'], now()->subYears(2)->toDateTimeString());
        Booklet::entries()->forget();
    }

    protected function titles($component): array
    {
        return collect($component->viewData('entries'))->map(fn ($e) => $e->title())->sort()->values()->all();
    }

    protected function table(array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($this->admin)->test(Entries::class, ['collection' => 'posts']);
    }

    public function test_by_who_wrote_it(): void
    {
        $this->assertSame(['Kuih lapis', 'Market day'], $this->titles($this->table()->set('by', (string) $this->hana->id)));
        $this->assertContains('Pandan chiffon', $this->titles($this->table()->set('by', 'me')));
        $this->assertNotContains('Kuih lapis', $this->titles($this->table()->set('by', 'me')));
    }

    public function test_by_category_tag_or_none(): void
    {
        $this->assertSame(['Kuih lapis', 'Pandan chiffon'], $this->titles($this->table()->set('in.categories', 'baking')));
        $this->assertSame(['Pandan chiffon'], $this->titles($this->table()->set('in.categories', 'baking')->set('in.tags', 'pandan')));
        $this->assertContains('Market day', $this->titles($this->table()->set('in.categories', '-')));
        $this->assertNotContains('Kuih lapis', $this->titles($this->table()->set('in.categories', '-')));
    }

    public function test_by_date_and_by_last_edit(): void
    {
        $this->assertNotContains('Kuih lapis', $this->titles($this->table()->set('when', 'week')));
        $this->assertContains('Pandan chiffon', $this->titles($this->table()->set('when', 'week')));
        $this->assertSame(['Market day'], array_values(array_intersect(['Pandan chiffon', 'Kuih lapis', 'Market day'], $this->titles($this->table()->set('when', 'older')))));
        $this->assertContains('Pandan chiffon', $this->titles($this->table()->set('edited', 'today')));
        $this->assertNotContains('Pandan chiffon', $this->titles($this->table()->set('edited', 'stale')));
    }

    public function test_search_titles_or_everything(): void
    {
        $this->assertSame(['Pandan chiffon'], $this->titles($this->table()->set('search', 'pandan')));
        $this->assertSame(['Market day', 'Pandan chiffon'], $this->titles($this->table()->set('search', 'pandan')->set('within', 'everything')));
        $this->assertSame(['Kuih lapis'], $this->titles($this->table()->set('search', 'coconut layers')->set('within', 'everything'))); // every word, any order
        $this->assertSame(['Kuih lapis', 'Market day'], $this->titles($this->table()->set('search', 'hana')->set('within', 'everything'))); // the writer's name
    }

    public function test_filters_show_as_chips_and_clear(): void
    {
        $table = $this->table(['by' => 'me', 'when' => 'month'])
            ->assertSet('filtersOpen', true)
            ->assertSee('dash-chip', false)
            ->assertSee('Last 30 days');

        $table->call('dropFilter', 'when')->assertSet('when', '')->assertSet('by', 'me');
        $table->call('clearFilters')->assertSet('by', '')->assertSet('in', [])->assertSet('search', '');
    }

    public function test_odd_values_in_the_address_are_ignored(): void
    {
        $table = $this->table(['when' => 'forever', 'edited' => 'soon', 'within' => 'brain', 'in' => ['nope' => 'x', 'tags' => 'never-used']]);
        $table->assertSet('when', '')->assertSet('edited', '')->assertSet('within', 'titles')->assertSet('in', []);
    }

    public function test_the_book_layout_is_untouched(): void
    {
        YourBook::save($this->admin, ['layout' => 'book']);
        Livewire::withQueryParams(['by' => 'me'])->actingAs($this->admin->fresh())->test(Entries::class, ['collection' => 'posts'])
            ->assertSet('by', '')->assertDontSee('dash-filters', false);
    }
}
