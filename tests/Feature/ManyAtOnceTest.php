<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Entries;
use App\Models\ActivityLog;
use App\Models\BinItem;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ticking posts in a chapter's list and doing one thing to all of them:
 * publish, make drafts, add a category or tag, move to the bin.
 */
class ManyAtOnceTest extends TestCase
{
    use RefreshDatabase;

    protected User $editor;

    protected User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->editor = User::factory()->create(['role' => 'editor', 'name' => 'Nur']);
        $this->writer = User::factory()->create(['role' => 'writer', 'name' => 'Hana']);
    }

    protected function makePost(string $title, User $by, bool $live = false, array $data = [])
    {
        return Booklet::entries()->make(
            ['collection' => 'posts', 'published' => $live, 'date' => $live ? now()->subDay()->toDateTimeString() : null],
            ['title' => $title, 'content' => 'Words.', 'author' => $by->id] + $data,
        )->save();
    }

    public function test_an_editor_publishes_and_unpublishes_many(): void
    {
        $a = $this->makePost('First draft', $this->writer);
        $b = $this->makePost('Second draft', $this->editor);
        $out = $this->makePost('Already out', $this->editor, live: true);

        Livewire::actingAs($this->editor)->test(Entries::class, ['collection' => 'posts'])
            ->assertSee('Choose all')
            ->set('ticked', [$a->id(), $b->id(), $out->id()])
            ->assertSee('3 chosen')
            ->call('publishTicked')
            ->assertSet('ticked', [])
            ->assertDispatched('stamp', text: '2 published.');

        $this->assertSame('published', Booklet::entries()->find($a->id())->status());
        $this->assertSame('published', Booklet::entries()->find($b->id())->status());
        $this->assertNotNull(Booklet::entries()->find($a->id())->date(), 'out today');
        $this->assertSame(2, ActivityLog::where('action', 'published')->count());
        $this->assertGreaterThan(0, Booklet::revisions()->whereKey($a->revisionKey())->count(), 'kept in its history');

        Livewire::actingAs($this->editor)->test(Entries::class, ['collection' => 'posts'])
            ->set('ticked', [$a->id(), $out->id()])
            ->call('draftTicked')
            ->assertDispatched('stamp', text: '2 made drafts. Readers can’t see them now.');
        $this->assertSame('draft', Booklet::entries()->find($out->id())->status());
        $this->get($out->uri())->assertNotFound();
    }

    public function test_a_writer_cant_publish_and_only_changes_their_own(): void
    {
        $mine = $this->makePost('Hana’s', $this->writer);
        $theirs = $this->makePost('Nur’s', $this->editor);

        $list = Livewire::actingAs($this->writer)->test(Entries::class, ['collection' => 'posts'])
            ->set('ticked', [$mine->id(), $theirs->id()])
            ->assertDontSee('Make drafts');
        $list->call('publishTicked')->assertForbidden();

        Livewire::actingAs($this->writer)->test(Entries::class, ['collection' => 'posts'])
            ->set('ticked', [$mine->id(), $theirs->id()])
            ->call('binTicked');
        $this->assertNull(Booklet::entries()->find($mine->id()));
        $this->assertNotNull(Booklet::entries()->find($theirs->id()), 'not hers to bin');
        $this->assertSame(1, BinItem::count());
    }

    public function test_adding_a_tag_and_putting_in_a_category(): void
    {
        $a = $this->makePost('One', $this->editor, data: ['tags' => ['kopi'], 'categories' => ['news']]);
        $b = $this->makePost('Two', $this->editor);

        $list = Livewire::actingAs($this->editor)->test(Entries::class, ['collection' => 'posts'])
            ->assertSet('bulkField', 'categories')
            ->set('ticked', [$a->id(), $b->id()])
            ->set('bulkField', 'tags')->set('bulkWords', 'Pandan Cake')
            ->call('termTicked')
            ->assertDispatched('stamp', text: '“Pandan Cake” added to 2.');
        $this->assertSame(['kopi', 'pandan-cake'], Booklet::entries()->find($a->id())->get('tags'));
        $this->assertSame(['pandan-cake'], Booklet::entries()->find($b->id())->get('tags'));
        $this->assertSame('Pandan Cake', Booklet::terms()->findBySlug('tags', 'pandan-cake')?->title(), 'a new tag is made');

        // A post has room for one category: the new one takes its place.
        $list->set('ticked', [$a->id(), $b->id()])->set('bulkField', 'categories')->set('bulkWords', 'Recipes')->call('termTicked');
        $this->assertSame(['recipes'], Booklet::entries()->find($a->id())->get('categories'));
        $this->assertSame(['recipes'], Booklet::entries()->find($b->id())->get('categories'));

        $list->set('ticked', [$a->id()])->set('bulkWords', '  ')->call('termTicked')->assertHasErrors('bulkWords');
    }

    public function test_choose_all_ticks_the_page_and_binning_many_can_be_found_in_the_bin(): void
    {
        $posts = collect(range(1, 3))->map(fn ($i) => $this->makePost("Post $i", $this->editor));
        $ids = $posts->map->id()->all();

        $list = Livewire::actingAs($this->editor)->test(Entries::class, ['collection' => 'posts'])
            ->call('tickAll', $ids)->assertSet('ticked', $ids)
            ->call('tickAll', $ids)->assertSet('ticked', [])
            ->call('tickAll', $ids)
            ->call('binTicked')
            ->assertDispatched('stamp', text: '3 moved to the bin. They wait there for 30 days.');

        $this->assertSame(3, BinItem::count());
        $this->assertSame(3, ActivityLog::where('action', 'moved to the bin')->count());
        $list->assertSet('ticked', []);
    }

    public function test_ticks_for_entries_elsewhere_are_ignored(): void
    {
        $page = Booklet::entries()->whereCollection('pages')->first();
        $this->assertNotNull($page);

        Livewire::actingAs($this->editor)->test(Entries::class, ['collection' => 'posts'])
            ->set('ticked', [$page->id()])
            ->assertSet('ticked', [])
            ->call('binTicked');
        $this->assertNotNull(Booklet::entries()->find($page->id()));
    }
}
