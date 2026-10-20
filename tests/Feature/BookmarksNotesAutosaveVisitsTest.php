<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Contents;
use App\Livewire\Admin\DogEar;
use App\Livewire\Admin\EntryEditor;
use App\Livewire\Admin\EntryNotes;
use App\Models\DogEar as Fold;
use App\Models\EntryNote;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dog-ears, sticky notes for the team, autosave with who's editing, and Visits.
 */
class BookmarksNotesAutosaveVisitsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur']);
    }

    // Dog-ears ----------------------------------------------------------------

    public function test_folding_a_corner_bookmarks_the_page_and_shows_on_contents(): void
    {
        $this->actingAs($this->admin);

        $ear = Livewire::test(DogEar::class)->call('toggle', 'Posts · Booklet')->assertSet('folded', true);
        $this->assertSame('Posts', Fold::first()->title);

        Livewire::test(Contents::class)->assertSee('Dog-eared pages')->assertSee('Posts');

        $ear->call('toggle', 'Posts · Booklet')->assertSet('folded', false);
        $this->assertSame(0, Fold::count());
    }

    // Sticky notes ----------------------------------------------------------

    public function test_team_notes_can_be_added_ticked_off_and_removed(): void
    {
        $this->actingAs($this->admin);
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');

        $notes = Livewire::test(EntryNotes::class, ['entryId' => $post->id()])
            ->set('body', 'Check this quote')->call('add')
            ->assertSee('Check this quote');

        $note = EntryNote::first();
        $notes->call('toggleDone', $note->id);
        $this->assertNotNull($note->fresh()->done_at);
        $this->assertSame([], EntryNote::openCounts());

        $notes->call('remove', $note->id);
        $this->assertSame(0, EntryNote::count());
    }

    public function test_writers_cannot_throw_away_someone_elses_note(): void
    {
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $note = EntryNote::create(['entry_id' => $post->id(), 'user_id' => $this->admin->id, 'body' => 'Mine']);

        $this->actingAs(User::factory()->create(['role' => 'writer']));
        Livewire::test(EntryNotes::class, ['entryId' => $post->id()])->call('remove', $note->id)->assertForbidden();
    }

    // Autosave ------------------------------------------------------------------

    public function test_a_new_draft_autosaves_without_leaving_the_page(): void
    {
        $this->actingAs($this->admin);

        $editor = Livewire::test(EntryEditor::class, ['collection' => 'posts'])
            ->set('values.title', 'Written in a hurry')
            ->set('values.content', 'Words that must not be lost.');

        /** @var EntryEditor $component */
        $component = $editor->instance();
        $this->assertSame('saved', $component->autosave());
        $this->assertNotNull($editor->get('entryId'));
        $this->assertSame('Words that must not be lost.', Booklet::entries()->find($editor->get('entryId'))->get('content'));
    }

    public function test_published_entries_wait_for_the_save_button(): void
    {
        $this->actingAs($this->admin);
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $post->setAttribute('published', true)->save();

        $editor = Livewire::test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->set('values.title', 'Half-finished');

        /** @var EntryEditor $component */
        $component = $editor->instance();
        $this->assertSame('manual', $component->autosave());
        Booklet::flush();
        $this->assertSame('Hello from Booklet', Booklet::entries()->find($post->id())->title());
    }

    public function test_the_entry_being_edited_cannot_be_switched_from_the_browser(): void
    {
        $this->actingAs($this->admin);
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])->set('entryId', 'someone-elses');
    }

    public function test_others_editing_and_newer_saves_are_reported(): void
    {
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $hana = User::factory()->create(['role' => 'editor', 'name' => 'Hana']);

        $this->actingAs($this->admin);
        $mine = Livewire::test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()]);

        $this->actingAs($hana);
        $theirs = Livewire::test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()]);
        /** @var EntryEditor $theirsEditor */
        $theirsEditor = $theirs->instance();
        $theirsEditor->heartbeat(true);
        $this->travel(2)->seconds(); // saves are compared to the second
        $theirs->set('values.title', 'Hana’s title')->call('save');

        $this->actingAs($this->admin);
        /** @var EntryEditor $mineEditor */
        $mineEditor = $mine->instance();
        $beat = $mineEditor->heartbeat();
        $this->assertSame('Hana', $beat['others'][0]['name']);
        $this->assertTrue($beat['others'][0]['typing']);
        $this->assertSame('Hana', $beat['newer']['name']);
    }

    // Visits --------------------------------------------------------------------

    public function test_visits_are_counted_by_page_and_source_without_cookies(): void
    {
        $headers = ['Referer' => 'https://www.google.com/search?q=booklet', 'User-Agent' => 'Mozilla/5.0 Test'];
        $this->get('/blog/hello-from-booklet', $headers)->assertOk()->assertCookieMissing('booklet_visitor');
        $this->get('/blog/hello-from-booklet', $headers)->assertOk();
        $this->postJson('/read', ['path' => '/blog/hello-from-booklet'])->assertNoContent();

        $page = DB::table('page_stats')->where('path', '/blog/hello-from-booklet')->first();
        $this->assertSame(2, (int) $page->views);
        $this->assertSame(1, (int) $page->reads);
        $this->assertNotNull($page->entry_id);
        $this->assertSame(2, (int) DB::table('referrer_stats')->where('source', 'Google')->value('views'));
        $this->assertSame(1, (int) DB::table('site_visits')->value('visitors'));

        $this->actingAs($this->admin)->get(route('admin.visits'))->assertOk()
            ->assertSee('Hello from Booklet')->assertSee('Google');
    }

    public function test_do_not_track_only_counts_the_total(): void
    {
        $this->get('/blog/hello-from-booklet', ['DNT' => '1'])->assertOk();
        $this->assertSame(1, (int) DB::table('site_visits')->value('views'));
        $this->assertSame(0, DB::table('page_stats')->count());
    }
}
