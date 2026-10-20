<?php

namespace Tests\Feature;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Editions;
use App\Models\User;
use App\Support\EditionCompare;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Editions: what changed, compared any way, and putting things back.
 */
class EditionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Entry $post;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur']);
        $this->actingAs($this->admin);

        $this->post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        // Edition 1: as it starts. Edition 2: new words, standfirst and tags. Edition 3: a new address.
        $this->edition([]);
        $this->edition(['content' => 'A brand new opening line.', 'excerpt' => 'Notes from a small kitchen.', 'tags' => ['baking', 'pandan']]);
        $this->edition(['slug' => 'hello-again']);
    }

    protected function edition(array $changes): void
    {
        foreach ($changes as $key => $value) {
            $key === 'slug' ? $this->post->setAttribute('slug', $value) : $this->post->set($key, $value);
        }
        $this->post->save();
        Booklet::revisions()->snapshot($this->post->revisionKey(), $this->post, 'edited', (string) $this->admin->id);
        $this->travel(1)->minutes();
    }

    protected function page()
    {
        return Livewire::test(Editions::class, ['collection' => 'posts', 'entry' => $this->post->id()]);
    }

    protected function editionId(int $number): string
    {
        $all = Booklet::revisions()->whereKey($this->post->revisionKey());

        return $all->get($all->count() - $number)->id();
    }

    public function test_each_edition_shows_its_words_and_everything_else_that_changed(): void
    {
        $this->page()->call('pick', $this->editionId(2))
            ->assertSeeText('From Edition 1 to Edition 2')
            ->assertSee('A brand new opening line.')
            ->assertSee('Everything else')
            ->assertSee('Standfirst')
            ->assertSee('Notes from a small kitchen.')
            ->assertSee('Tags')
            ->assertSee('baking, pandan')
            ->assertDontSee('hello-again');

        $this->page()->call('pick', $this->editionId(3))
            ->assertSee('Address')
            ->assertSee('hello-from-booklet')
            ->assertSee('hello-again')
            ->assertDontSee('Notes from a small kitchen.');
    }

    public function test_comparing_with_now_or_any_edition_and_side_by_side(): void
    {
        $page = $this->page()->call('pick', $this->editionId(1))->call('compareWith', 'now')
            ->assertSeeText('From Edition 1 to Now, in the editor')
            ->assertSee('Put back edition 1’s');

        // Another edition: always told older-to-newer, whichever was picked first.
        $page->call('compareWith', $this->editionId(3))->assertSeeText('From Edition 1 to The latest edition');
        $this->page()->call('pick', $this->editionId(3))->call('compareWith', $this->editionId(1))
            ->assertSeeText('From Edition 1 to The latest edition');

        // Side by side: each side named, words marked on their own side.
        $side = $this->page()->call('pick', $this->editionId(2))->call('setView', 'side')->assertSet('view', 'side');
        $html = $side->html();
        $this->assertStringContainsString('diff-side', $html);
        $this->assertMatchesRegularExpression('/diff-side__head">Edition 1.*diff-side__head">Edition 2/s', $html);

        // Rubbish in the address bar falls back to the edition before.
        $this->page()->call('compareWith', 'nonsense')->assertSet('against', 'previous');
    }

    public function test_putting_back_one_field_leaves_the_rest_alone(): void
    {
        $this->page()->call('pick', $this->editionId(1))->call('putBack', 'excerpt')->assertDispatched('stamp');

        $post = Booklet::entries()->find($this->post->id());
        $this->assertNull($post->get('excerpt'));                        // the standfirst is as it was in edition 1
        $this->assertSame('A brand new opening line.', $post->get('content')); // the words are as they are now
        $this->assertSame(['baking', 'pandan'], $post->get('tags'));
        $this->assertSame('hello-again', $post->slug());

        $latest = Booklet::revisions()->whereKey($post->revisionKey())->first();
        $this->assertSame('Put back the Standfirst from edition 1', $latest->message());
        $this->assertDatabaseHas('activity_log', ['action' => 'put back the Standfirst from edition 1 of']);

        // The address too (the old one forwards to the new, as with any move).
        $this->page()->call('pick', $this->editionId(1))->call('putBack', 'slug');
        $this->assertSame('hello-from-booklet', Booklet::entries()->find($this->post->id())->slug());

        // Something that isn't a field does nothing.
        $this->page()->call('putBack', 'password');
    }

    public function test_restoring_all_of_an_edition(): void
    {
        $page = $this->page()->call('pick', $this->editionId(1))->call('restore');
        $post = Booklet::entries()->find($this->post->id());
        $this->assertSame('hello-from-booklet', $post->slug());
        $this->assertNull($post->get('excerpt'));
        $this->assertSame(4, Booklet::revisions()->whereKey($post->revisionKey())->count());

        // The latest edition is now what's in the editor: nothing to restore.
        $this->page()->assertSee('This is what’s in the editor');
    }

    public function test_values_read_the_way_people_say_them(): void
    {
        $fields = EditionCompare::fields($this->post);
        $this->assertSame('Standfirst', $fields['excerpt']['label']);
        $this->assertSame('Meta description', $fields['meta_description']['label']); // from the SEO fieldset
        $this->assertSame('Nur', EditionCompare::words($this->admin->id, $fields['author']));
        $this->assertSame('3 Oct 2026, 9:00am', EditionCompare::words('2026-10-03 09:00:00', $fields['date']));
        $this->assertSame('cake.jpg', EditionCompare::words(['blog/cake.jpg'], $fields['cover']));
        $this->assertTrue(EditionCompare::same(null, '', $fields['excerpt']));
        $this->assertTrue(EditionCompare::same('2026-10-03 09:00', '2026-10-03 09:00:00', $fields['date']));
    }
}
