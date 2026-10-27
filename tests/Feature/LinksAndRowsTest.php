<?php

namespace Tests\Feature;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Inserts\InsertManager;
use App\Livewire\Admin\Collections;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use App\Support\EditionCompare;
use App\Support\Links;
use App\Support\Rows;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Linked entries (Statamic's Entries field) and rows (Statamic's Grid).
 */
class LinksAndRowsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        config(['booklet.inserts_path' => base_path('tests/Fixtures/inserts')]);
        $this->app->forgetInstance(InsertManager::class);
    }

    protected function about(): Entry
    {
        return Booklet::entries()->findBySlug('pages', 'about');
    }

    protected function makePost(string $title, bool $published = true): Entry
    {
        $post = Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'slug' => \Illuminate\Support\Str::slug($title), 'published' => $published, 'date' => now()->subDay()], ['title' => $title, 'content' => 'Words about '.$title]);
        $post->save();

        return $post;
    }

    /** Give the Page blueprint another field. */
    protected function pageField(string $handle, array $config): void
    {
        Booklet::blueprints()->findIn('collections.pages', 'page')->addField($handle, $config)->save();
        Booklet::flush();
    }

    protected function editAbout()
    {
        return Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()]);
    }

    protected function read(string $url): string
    {
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);

        return $this->get($url)->assertOk()->getContent();
    }

    public function test_collections_set_up_linked_entries_and_rows(): void
    {
        $page = Livewire::actingAs($this->admin)->test(Collections::class)
            ->call('pick', 'pages')
            ->assertSee('+ Linked entries')
            ->assertSee('+ Rows')
            ->call('addField', 'entries')
            ->call('addField', 'grid');
        $fields = $page->get('fields');
        $links = array_key_last($fields) - 1;
        $grid = array_key_last($fields);

        $page->set("fields.$links.from", 'posts')->set("fields.$links.max", '2')
            ->set("fields.$grid.display", 'Ingredients')->set("fields.$grid.handle", 'ingredients')
            ->set("fields.$grid.columns", 'Amount (number), Item, Photo (picture), Organic (yes or no), Shop (url), id')
            ->call('save')->assertHasNoErrors();

        $bp = Booklet::blueprints()->findIn('collections.pages', 'page');
        $this->assertSame(['type' => 'entries', 'display' => 'Linked entries', 'collections' => ['posts'], 'max_items' => 2], $bp->field('linked_entries'));
        $this->assertSame(
            ['amount' => 'number', 'item' => 'text', 'photo' => 'picture', 'organic' => 'toggle', 'shop' => 'link', 'col_id' => 'text'],
            array_map(fn ($c) => $c['type'], Rows::columns($bp->field('ingredients'))),
        );

        // Shown back the way it was typed; renaming the list keeps each column's kind.
        $page = Livewire::actingAs($this->admin)->test(Collections::class)->call('pick', 'pages');
        $row = collect($page->get('fields'))->search(fn ($f) => $f['handle'] === 'ingredients');
        $this->assertSame('Amount (number), Item, Photo (picture), Organic (toggle), Shop (link), id', $page->get("fields.$row.columns"));
        $page->set("fields.$row.columns", 'Amount, Item')->call('save');
        $this->assertSame(['amount' => 'number', 'item' => 'text'], array_map(fn ($c) => $c['type'], Rows::columns(Booklet::blueprints()->findIn('collections.pages', 'page')->field('ingredients'))));
    }

    public function test_entries_are_linked_from_the_shelf(): void
    {
        $this->pageField('related', ['type' => 'entries', 'display' => 'Related', 'collections' => ['posts'], 'max_items' => 2]);
        $kopi = $this->makePost('Kopi at dawn');
        $kuih = $this->makePost('Kuih lapis');
        $draft = $this->makePost('Half-written', false);
        $contact = Booklet::entries()->findBySlug('pages', 'contact');

        $editor = $this->editAbout()
            ->assertSee('Link a post')
            ->call('openShelf', 'related')
            ->assertSee('Link from the shelf')
            ->assertSee('Kopi at dawn');
        // Pages aren't on this shelf.
        $this->assertNotContains('contact', $editor->instance()->shelfEntries->map->slug()->all());
        $this->assertNotContains($this->about()->id(), $editor->instance()->shelfEntries->map->id()->all());

        $editor->set('linkSearch', 'kuih')->assertSee('Kuih lapis');
        $this->assertNotContains($kopi->id(), $editor->instance()->shelfEntries->map->id()->all(), 'found by its words');
        $editor->call('linkEntry', $kuih->id())
            ->call('linkEntry', $contact->id())       // not from Posts: ignored
            ->call('linkEntry', $kuih->id())          // already there
            ->call('linkEntry', $draft->id())
            ->assertSet('linking', null)              // two is the most: the shelf closes
            ->assertSet('values.related', [$kuih->id(), $draft->id()])
            ->assertSee('That’s all 2 it holds')
            ->call('moveLink', 'related', 1, 0)
            ->call('unlinkEntry', 'related', $kuih->id())
            ->call('openShelf', 'related')->call('linkEntry', $kopi->id())
            ->call('save')->assertHasNoErrors();

        $this->assertSame([$draft->id(), $kopi->id()], Booklet::entries()->find($this->about()->id())->get('related'));

        // Readers see the published one only, as a card under the field's name.
        $html = $this->read('/about');
        $this->assertStringContainsString('class="linked"', $html);
        $this->assertStringContainsString('Related', $html);
        $this->assertStringContainsString('Kopi at dawn', $html);
        $this->assertStringNotContainsString('Half-written', $html);

        // From the other side: who links here, and a warning before tearing it out.
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $kopi->id()])
            ->assertSee('Linked from')
            ->assertSee('About')
            ->assertSee('1 page links here');
    }

    public function test_links_are_checked_before_they_are_kept(): void
    {
        $post = $this->makePost('Kopi at dawn');
        $page = $this->about();

        $this->assertSame([$post->id()], Links::clean([$post->id(), $post->id(), 'no-such-entry-123', '../../etc', 42, $page->id()], ['collections' => ['posts']], null));
        $this->assertSame([], Links::clean([$page->id()], [], $page->id())); // not itself

        $this->pageField('related', ['type' => 'entries', 'display' => 'Related']);
        $this->editAbout()->call('openShelf', 'title')->assertStatus(404);
        $this->editAbout()->call('openShelf', 'related.0.x')->assertStatus(404);

        // A translation's tie to its original isn't a link.
        $copy = Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post'], ['title' => 'Kopi waktu subuh', 'translation_of' => $post->id(), 'author' => 1]);
        $copy->save();
        $this->assertTrue(Links::to($post)->isEmpty());
    }

    public function test_rows_are_written_on_a_lined_card(): void
    {
        $this->pageField('ingredients', ['type' => 'grid', 'display' => 'Ingredients', 'fields' => [
            ['handle' => 'amount', 'field' => ['type' => 'text', 'display' => 'Amount']],
            ['handle' => 'item', 'field' => ['type' => 'text', 'display' => 'Item']],
            ['handle' => 'photo', 'field' => ['type' => 'assets', 'display' => 'Photo']],
            ['handle' => 'shop', 'field' => ['type' => 'link', 'display' => 'Where to buy']],
        ]]);
        $flour = (new AssetUploader('media'))->store(UploadedFile::fake()->image('flour.jpg', 600, 600), 'blog')->path();

        $editor = $this->editAbout()
            ->assertSee('Ingredients')
            ->assertSee('+ Add a row')
            ->call('addRow', 'ingredients')->call('addRow', 'ingredients')->call('addRow', 'ingredients')
            ->set('values.ingredients.0.amount', '2')->set('values.ingredients.0.item', 'Eggs')
            ->set('values.ingredients.1.amount', '200g')->set('values.ingredients.1.item', 'Pandan flour')
            ->set('values.ingredients.1.shop', 'javascript:alert(1)')
            ->call('openRowPicker', 'ingredients', 1, 'photo')->call('choose', $flour)
            ->assertSet('picking', null)
            ->call('moveRow', 'ingredients', 1, 0)
            ->call('removeRow', 'ingredients', 1)->assertSee('Took out a row')->call('undoRemoveRow')
            ->call('save')->assertHasNoErrors();

        $rows = Booklet::entries()->find($this->about()->id())->get('ingredients');
        $this->assertCount(2, $rows); // the empty third row isn't kept
        $this->assertSame('Pandan flour', $rows[0]['item']);
        $this->assertSame([$flour], $rows[0]['photo']);
        $this->assertArrayNotHasKey('shop', $rows[0]); // unsafe link dropped
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $rows[1]['id']);

        $html = $this->read('/about');
        $this->assertStringContainsString('class="rows-table"', $html);
        $this->assertStringContainsString('<th scope="col"', $html);
        $this->assertStringContainsString('Pandan flour', $html);
        $this->assertStringContainsString('flour.jpg', $html);
        $this->get('/search?q=pandan+flour')->assertOk()->assertSee('About');

        $words = EditionCompare::words($rows, ['type' => 'grid', 'options' => [], 'taxonomy' => null, 'config' => $this->about()->blueprint()->field('ingredients')]);
        $this->assertSame('2 rows: 200g Pandan flour flour.jpg; 2 Eggs', $words);

        $this->editAbout()->call('addRow', 'title')->assertStatus(404);
        $this->editAbout()->call('openRowPicker', 'ingredients', 0, 'item')->assertStatus(404);
    }

    public function test_a_featured_cut_out_links_entries_too(): void
    {
        $kopi = $this->makePost('Kopi at dawn');

        $this->editAbout()
            ->call('addBlock', 'blocks', 'featured')
            ->assertSee('Link a post or page')
            ->call('openShelf', 'blocks.0.entries')
            ->call('linkEntry', $kopi->id())
            ->set('values.blocks.0.heading', 'Start here')
            ->call('save')->assertHasNoErrors();

        $kept = Booklet::entries()->find($this->about()->id())->get('blocks')[0];
        $this->assertSame([$kopi->id()], $kept['entries']);

        $html = $this->read('/about');
        $this->assertStringContainsString('block--featured', $html);
        $this->assertStringContainsString('Start here', $html);
        $this->assertStringContainsString('Kopi at dawn', $html);

        $this->assertSame('About', Links::to($kopi)->first()?->title());
        $this->assertSame('Featured “Start here”', \App\Support\Blocks::summary([$kept]));
    }
}
