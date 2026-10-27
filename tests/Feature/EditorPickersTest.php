<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Choosing instead of typing on the writing page: categories and tags from
 * the ones there are (most used first), and who a post is credited to.
 */
class EditorPickersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah']);
    }

    protected function makePost(string $slug, array $data): \App\Booklet\Data\Entry
    {
        return Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'slug' => $slug, 'published' => true],
            $data + ['title' => ucfirst($slug)])->save();
    }

    public function test_tags_and_categories_are_chosen_from_the_ones_there_are(): void
    {
        foreach (['Baking', 'Kuih', 'Weekend'] as $t) {
            Booklet::terms()->make(['taxonomy' => 'tags', 'slug' => \Illuminate\Support\Str::slug($t)], ['title' => $t])->save();
        }
        $this->makePost('one', ['tags' => ['kuih', 'baking']]);
        $this->makePost('two', ['tags' => ['kuih']]);
        $entry = $this->makePost('three', []);

        $page = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $entry->id()])
            ->assertSee('Choose from the ones you’ve used')->assertSee('x-data="bookletTerms(', false)
            ->assertSee('Choose one, or type a new one and press Enter.'); // the category: one only
        $tags = $page->viewData('termChoices')['tags'];
        $this->assertSame(['Kuih', 'Baking'], array_slice(array_column($tags, 'title'), 0, 2), 'most used first');
        $this->assertSame([2, 1], array_slice(array_column($tags, 'count'), 0, 2));

        // What the picker leaves in the box is saved as before: existing ones by their slug, new ones added.
        $page->set('termInputs.tags', 'Kuih, Rainy days')->call('save')->assertHasNoErrors();
        Booklet::flush();
        $this->assertSame(['kuih', 'rainy-days'], Booklet::entries()->find($entry->id())->get('tags'));
        $this->assertNotNull(Booklet::terms()->findBySlug('tags', 'rainy-days'));
    }

    public function test_editors_credit_a_post_to_someone_else_and_writers_write_as_themselves(): void
    {
        $hana = User::factory()->create(['role' => 'writer', 'name' => 'Hana Rahman', 'byline' => 'Hana R.']);

        // A new post is the writer's own.
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts'])
            ->assertSet('author', (string) $this->admin->id)->assertSee('Written by')->assertSee('Hana R. (Hana Rahman)');

        $entry = $this->makePost('kaya', ['author' => $this->admin->id]);
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $entry->id()])
            ->assertSet('author', (string) $this->admin->id)
            ->set('author', (string) $hana->id)->call('save')->assertHasNoErrors();
        Booklet::flush();
        $this->assertSame($hana->id, (int) Booklet::entries()->find($entry->id())->get('author'));

        // Someone who isn't in the book: left as it was.
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $entry->id()])
            ->set('author', '99999')->call('save');
        Booklet::flush();
        $this->assertSame($hana->id, (int) Booklet::entries()->find($entry->id())->get('author'));

        // A writer doesn't get the choice, and can't make it by hand.
        $mine = $this->makePost('roti', ['author' => $hana->id, 'published' => false]);
        $mine->setAttribute('published', false)->save();
        Livewire::actingAs($hana)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $mine->id()])
            ->assertDontSee('Written by')->set('author', (string) $this->admin->id)->call('save');
        Booklet::flush();
        $this->assertSame($hana->id, (int) Booklet::entries()->find($mine->id())->get('author'));
    }
}
