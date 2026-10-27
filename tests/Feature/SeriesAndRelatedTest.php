<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use App\Support\Related;
use App\Support\Series;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Posts in a numbered series ("Part 2 of 5", with the parts either side),
 * and "More like this" at the end of a post.
 */
class SeriesAndRelatedTest extends TestCase
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

    protected function makePost(string $slug, array $data = [], bool $published = true, int $daysAgo = 1): \App\Booklet\Data\Entry
    {
        return Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'slug' => $slug, 'published' => $published, 'date' => now()->subDays($daysAgo)],
            $data + ['title' => ucfirst(str_replace('-', ' ', $slug)), 'content' => 'Words.'])->save();
    }

    protected function visit(string $url)
    {
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        \App\Support\PageCache::flush();

        return $this->get($url);
    }

    public function test_a_series_reads_in_order_with_the_parts_either_side(): void
    {
        // Out of date order on purpose: the part numbers decide.
        $this->makePost('the-dough', ['series' => 'Kuih from scratch', 'series_part' => 2], daysAgo: 9);
        $this->makePost('the-pandan', ['series' => 'Kuih from scratch', 'series_part' => 1], daysAgo: 3);
        $this->makePost('the-steaming', ['series' => 'kuih  from scratch '], daysAgo: 1); // no number: after the numbered ones; the name matches loosely
        $this->makePost('the-draft-part', ['series' => 'Kuih from scratch', 'series_part' => 4], published: false);

        $page = $this->visit('/blog/the-dough')->assertOk()
            ->assertSee('Part 2 of 3')->assertSee('Kuih from scratch')
            ->assertSee('← Part 1 of 3', false)->assertSee('Part 3 of 3 →', false)
            ->assertSee('<span aria-current="page">The dough</span>', false)
            ->assertDontSee('The draft part');
        $this->assertStringContainsString('>The pandan</span>', $page->getContent());

        $this->visit('/blog/the-steaming')->assertOk()->assertSee('Part 3 of 3')->assertSee('The last part')
            ->assertDontSee('Next page →', false);

        // A series of one isn't one yet; a post with none shows the posts either side as before.
        $this->makePost('lonely', ['series' => 'Only me']);
        $this->visit('/blog/lonely')->assertOk()->assertDontSee('Part 1 of 1')->assertDontSee('series-box', false);
        $this->assertSame(['Kuih from scratch' => 4, 'Only me' => 1], Series::all());
    }

    public function test_more_like_this_by_hand_then_categories_and_tags(): void
    {
        $this->makePost('tea-one', ['categories' => ['drinks'], 'tags' => ['tea', 'hot']], daysAgo: 6);
        $this->makePost('tea-two', ['categories' => ['drinks'], 'tags' => ['tea']], daysAgo: 5);
        $this->makePost('other-drink', ['categories' => ['drinks']], daysAgo: 4);
        $this->makePost('tea-only-tag', ['tags' => ['tea', 'hot']], daysAgo: 2);
        $page = Booklet::entries()->findBySlug('pages', 'about');
        $main = $this->makePost('tea-main', ['categories' => ['drinks'], 'tags' => ['tea', 'hot'], 'related' => [$page->id()], 'series' => 'Teatime', 'series_part' => 1]);
        $this->makePost('tea-main-two', ['categories' => ['drinks'], 'tags' => ['tea', 'hot'], 'series' => 'Teatime', 'series_part' => 2], daysAgo: 0);

        Booklet::flush();
        $posts = app(\App\Booklet\Site::class)->posts();
        $more = Related::for(Booklet::entries()->findBySlug('posts', 'tea-main'), $posts, 4)->map(fn ($e) => $e->slug())->all();
        // By hand first (a page is fine), then most in common (newer first among equals); never its own series.
        $this->assertSame(['about', 'tea-one', 'tea-two', 'tea-only-tag'], $more);

        $this->visit('/blog/tea-main')->assertOk()->assertSee('More like this')->assertSee('Tea one')
            ->assertSee('Part 1 of 2')->assertDontSee('More to read');

        // Nothing in common: the newest posts, so it's never empty.
        $plain = $this->makePost('plain', [], daysAgo: 30);
        $this->assertCount(3, Related::for($plain, $posts->push($plain)));
    }

    public function test_the_editor_sets_the_series_and_its_part(): void
    {
        $this->makePost('the-pandan', ['series' => 'Kuih from scratch', 'series_part' => 1]);
        $entry = $this->makePost('the-dough');

        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $entry->id()])
            ->assertSee('Part of a series')->assertSee('<option value="Kuih from scratch">', false)
            ->set('series', 'Kuih from scratch')->set('seriesPart', '0')->call('save')->assertHasErrors('seriesPart')
            ->set('seriesPart', '2')->call('save')->assertHasNoErrors();

        Booklet::flush();
        $saved = Booklet::entries()->find($entry->id());
        $this->assertSame('Kuih from scratch', $saved->get('series'));
        $this->assertSame(2, $saved->get('series_part'));

        $editor->set('series', '')->call('save')->assertHasNoErrors();
        Booklet::flush();
        $this->assertNull(Booklet::entries()->find($entry->id())->get('series'));
        $this->assertNull(Booklet::entries()->find($entry->id())->get('series_part'));

        // Pages aren't in series.
        $about = Booklet::entries()->findBySlug('pages', 'about');
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $about->id()])->assertDontSee('Part of a series');
    }
}
