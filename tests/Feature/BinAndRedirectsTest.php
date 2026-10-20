<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Booklet\Support\Menus;
use App\Booklet\Support\PageTree;
use App\Livewire\Admin\Bin as BinPage;
use App\Livewire\Admin\EntryEditor;
use App\Livewire\Admin\Redirects as RedirectsPage;
use App\Models\BinItem;
use App\Models\Comment;
use App\Models\Redirect;
use App\Models\User;
use App\Support\Bin;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bin (deleted things wait 30 days and go back exactly as they were) and
 * redirects (old addresses forward to new ones, never in chains).
 */
class BinAndRedirectsTest extends TestCase
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

    // The bin ------------------------------------------------------------------

    public function test_a_deleted_page_goes_back_to_its_place_and_its_menus(): void
    {
        $this->actingAs($this->admin);
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $this->assertTrue(Menus::has('header', $about->id()));

        Livewire::test(EntryEditor::class, ['collection' => 'pages', 'entry' => $about->id()])->call('delete');

        $this->assertNull(Booklet::entries()->find($about->id()));
        $this->assertFalse(Menus::has('header', $about->id()));
        $item = BinItem::firstOrFail();
        $this->assertSame('entry', $item->kind);
        $this->assertSame(session('stamp_undo'), $item->id, 'the stamp offers Undo');

        // Undo
        $this->postJson(route('admin.bin.restore', $item))->assertOk()->assertJsonPath('ok', true);

        $back = Booklet::entries()->find($about->id());
        $this->assertNotNull($back, 'same id');
        $this->assertSame('about', $back->slug());
        $this->assertTrue(PageTree::for('pages')->contains($about->id()));
        $this->assertTrue(Menus::has('header', $about->id()));
        $this->assertSame(0, BinItem::count());
    }

    public function test_a_restored_entry_takes_a_free_address_if_its_own_was_taken(): void
    {
        $this->actingAs($this->admin);
        $post = Booklet::entries()->whereCollection('posts')->first();
        $slug = $post->slug();
        $item = Bin::entry($post);

        Booklet::entries()->make(['collection' => 'posts', 'slug' => $slug], ['title' => 'Someone else'])->save();

        Bin::restore($item);
        $this->assertSame($slug.'-2', Booklet::entries()->find($post->id())->slug());
    }

    public function test_a_deleted_file_comes_back_with_the_file_itself(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs($this->admin);

        Storage::disk('public')->put('photos/cake.jpg', 'pretend jpeg');
        $asset = (new AssetUploader('media'))->record('photos/cake.jpg');
        $asset->set('alt', 'A cake')->save();

        $item = Bin::asset($asset);
        Storage::disk('public')->assertMissing('photos/cake.jpg');
        Storage::disk('local')->assertExists($item->stored_file);

        Bin::restore($item);
        Storage::disk('public')->assertExists('photos/cake.jpg');
        $this->assertSame('pretend jpeg', Storage::disk('public')->get('photos/cake.jpg'));
        $this->assertSame('A cake', Booklet::assets()->all()->first(fn ($a) => $a->path() === 'photos/cake.jpg')?->get('alt'));
        Storage::disk('local')->assertMissing($item->stored_file);
    }

    public function test_a_comment_comes_back_with_its_replies(): void
    {
        $this->actingAs($this->admin);
        $entry = Booklet::entries()->all()->first();
        $before = Comment::count(); // the starter note
        $note = Comment::create(['entry_id' => $entry->id(), 'author_name' => 'Ana', 'body' => 'Lovely', 'status' => 'approved']);
        $reply = Comment::create(['entry_id' => $entry->id(), 'parent_id' => $note->id, 'user_id' => $this->admin->id, 'author_name' => 'Me', 'body' => 'Thanks!', 'status' => 'approved']);

        $item = Bin::comment($note);
        $this->assertSame($before, Comment::count(), 'the note and its reply went together');

        Bin::restore($item);
        $this->assertSame($before + 2, Comment::count());
        $this->assertSame($note->id, Comment::find($reply->id)->parent_id);
    }

    public function test_writers_only_handle_their_own_things(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);
        $this->actingAs($this->admin);
        $item = Bin::entry(Booklet::entries()->whereCollection('posts')->first());

        $this->actingAs($writer)->postJson(route('admin.bin.restore', $item))->assertForbidden();
        Livewire::actingAs($writer)->test(BinPage::class)->assertDontSee($item->title);

        $mine = Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Mine', 'author' => $writer->id])->save();
        $this->actingAs($writer);
        $own = Bin::entry($mine);
        Livewire::actingAs($writer)->test(BinPage::class)->assertSee('Mine')->call('restore', $own->id);
        $this->assertNotNull(Booklet::entries()->find($mine->id()));
    }

    public function test_the_bin_clears_itself_after_thirty_days(): void
    {
        $this->actingAs($this->admin);
        Bin::entry(Booklet::entries()->whereCollection('posts')->first());

        $this->travel(Bin::DAYS - 1)->days();
        $this->assertSame(0, Bin::tidy());
        $this->travel(2)->days();
        $this->assertSame(1, Bin::tidy());
        $this->assertSame(0, BinItem::count());
    }

    // Redirects ------------------------------------------------------------------

    protected function livePost()
    {
        return Booklet::entries()->whereCollection('posts')->first(fn ($e) => $e->status() === 'published');
    }

    public function test_changing_a_live_address_forwards_the_old_one(): void
    {
        $post = $this->livePost();
        $old = $post->uri();

        $post->setAttribute('slug', 'a-better-name')->save();

        $this->assertSame('/blog/a-better-name', $post->uri());
        $this->get($old.'?ref=newsletter')->assertRedirect(url('/blog/a-better-name').'?ref=newsletter')->assertStatus(301);
        $this->assertSame(1, Redirect::first()->hits);
    }

    public function test_no_chains_and_a_live_page_always_wins(): void
    {
        $post = $this->livePost();
        $first = $post->uri();

        $post->setAttribute('slug', 'second')->save();
        $post->setAttribute('slug', 'third')->save();

        $this->assertSame('/blog/third', Redirect::where('from_path', $first)->value('to_path'), 'straight to the newest');
        $this->assertSame('/blog/third', Redirect::where('from_path', '/blog/second')->value('to_path'));

        // Back to the first name: that address is live again, so it no longer forwards.
        $post->setAttribute('slug', basename($first))->save();
        $this->assertFalse(Redirect::where('from_path', $first)->exists());
        $this->get($first)->assertOk();
    }

    public function test_drafts_moving_about_make_no_redirects(): void
    {
        $draft = Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Draft one'])->save();
        $draft->setAttribute('slug', 'draft-two')->save();

        $this->assertSame(0, Redirect::count());
    }

    public function test_editors_add_redirects_by_hand_but_not_loops(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(RedirectsPage::class)
            ->set('from', 'old-site/about.html')->set('to', '/about')->call('add')
            ->assertHasNoErrors();
        $this->assertSame('/about', Redirect::where('from_path', '/old-site/about.html')->value('to_path'));

        Livewire::test(RedirectsPage::class)->set('from', '/about')->set('to', '/old-site/about.html')->call('add')->assertHasErrors('to');
        Livewire::test(RedirectsPage::class)->set('from', '/x')->set('to', '/x')->call('add')->assertHasErrors('to');

        $this->actingAs(User::factory()->create(['role' => 'writer']))->get(route('admin.redirects'))->assertForbidden();
    }
}
