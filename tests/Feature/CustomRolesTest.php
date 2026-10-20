<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\EntryEditor;
use App\Livewire\Admin\Media;
use App\Livewire\Admin\People;
use App\Models\Role;
use App\Models\User;
use App\Stacy\StacyConfig;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Custom roles ("library cards"): acting like a writer or an editor, then
 * narrowed chapter by chapter, and an admin trying the book out as one.
 */
class CustomRolesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur']);
    }

    protected function card(string $name, string $base, array $chapters, bool $media = true, bool $stacy = true): Role
    {
        return Role::create(['key' => Role::keyFor($name), 'name' => $name, 'tone' => 'sky', 'base' => $base, 'chapters' => $chapters, 'media' => $media, 'stacy' => $stacy]);
    }

    public function test_admins_make_a_card_and_give_it_to_someone(): void
    {
        $hana = User::factory()->create(['role' => 'writer', 'name' => 'Hana']);

        $people = Livewire::actingAs($this->admin)->test(People::class)
            ->assertSee('Library cards')
            ->call('newCard')
            ->set('cardForm.name', 'Editor')->call('saveCard')->assertHasErrors('cardForm.name') // one of the book's own
            ->set('cardForm.name', 'Guest writer')
            ->set('cardForm.about', 'Writes for the blog now and then')
            ->set('cardForm.chapters.posts', 'publish') // a writer card can't publish: kept as write
            ->set('cardForm.chapters.pages', 'none')
            ->set('cardForm.media', false)
            ->call('saveCard')->assertHasNoErrors()
            ->assertDispatched('stamp', text: 'Card made. Give it to someone from their page.');

        $role = Role::where('name', 'Guest writer')->firstOrFail();
        $this->assertSame('guest_writer', $role->key);
        $this->assertSame(['posts' => 'write', 'pages' => 'none'], $role->chapters);
        $this->assertFalse($role->media);
        $this->assertDatabaseHas('activity_log', ['action' => 'made the library card']);

        $people->call('closeCard')->call('pick', $hana->id)
            ->assertSee('Guest writer')
            ->set('role', 'guest_writer')->call('saveRole')->assertHasNoErrors();
        $hana->refresh();
        $this->assertSame('guest_writer', $hana->role);
        $this->assertSame('Guest writer', $hana->roleName());
        $this->assertSame('writer', $hana->level());

        // Writers can't make cards.
        Livewire::actingAs($hana)->test(People::class)->call('newCard')->assertForbidden();
    }

    public function test_a_card_closes_chapters_and_media(): void
    {
        $this->card('Guest writer', 'writer', ['posts' => 'write', 'pages' => 'none'], media: false, stacy: false);
        $guest = User::factory()->create(['role' => 'guest_writer']);

        $this->actingAs($guest)->get(route('admin.entries', 'pages'))->assertForbidden();
        $this->actingAs($guest)->get(route('admin.entries.create', 'pages'))->assertForbidden();
        $this->actingAs($guest)->get(route('admin.entries', 'posts'))->assertOk()
            ->assertDontSee('href="'.route('admin.entries', 'pages').'"', false); // no Pages flag

        // Writes drafts in Posts, can't publish them.
        Livewire::actingAs($guest)->test(EntryEditor::class, ['collection' => 'posts'])
            ->set('values.title', 'My first visit')
            ->call('setStatus', 'published')->assertSet('status', 'draft')
            ->call('save')->assertHasNoErrors();
        $this->assertFalse(Booklet::entries()->findBySlug('posts', 'my-first-visit')->published());

        // Media: look, don't touch.
        Livewire::actingAs($guest)->test(Media::class)
            ->assertSee('can choose pictures that are already here')
            ->set('uploads', [UploadedFile::fake()->image('x.jpg')])->assertForbidden();

        $this->assertFalse(StacyConfig::canUse($guest));

        // Search doesn't open closed chapters either.
        $this->actingAs($guest);
        $found = (new \App\Support\SearchIndex('about'))->run();
        $this->assertStringNotContainsString(route('admin.entries.edit', ['pages', Booklet::entries()->findBySlug('pages', 'about')->id()]), json_encode($found));
    }

    public function test_an_editor_card_publishes_only_where_it_may(): void
    {
        Notification::fake();
        $this->card('Blog editor', 'editor', ['posts' => 'publish', 'pages' => 'write']);
        $blogEditor = User::factory()->create(['role' => 'blog_editor']);
        $writer = User::factory()->create(['role' => 'writer']);

        // Publishes posts…
        Livewire::actingAs($blogEditor)->test(EntryEditor::class, ['collection' => 'posts'])
            ->set('values.title', 'Market morning')->call('setStatus', 'published')->call('save')->assertHasNoErrors();
        $this->assertTrue(Booklet::entries()->findBySlug('posts', 'market-morning')->published());

        // …but only drafts in Pages, and only their own.
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $this->actingAs($blogEditor)->get(route('admin.entries.edit', ['pages', $about->id()]))->assertForbidden();
        Livewire::actingAs($blogEditor)->test(EntryEditor::class, ['collection' => 'pages'])
            ->set('values.title', 'Opening times')->call('setStatus', 'published')->assertSet('status', 'draft');

        // Acts like an editor elsewhere: menus are theirs to arrange.
        $this->actingAs($blogEditor)->get(route('admin.menus'))->assertOk();

        // A review asked in Posts reaches them; one in Pages doesn't.
        $post = Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'published' => false], ['title' => 'Draft', 'author' => $writer->id]);
        $post->save();
        \App\Support\Reviews::ask($writer, $post, '');
        Notification::assertSentTo($blogEditor, \App\Notifications\ReviewNews::class);

        $page = Booklet::entries()->make(['collection' => 'pages', 'blueprint' => 'page', 'published' => false], ['title' => 'Page draft', 'author' => $writer->id]);
        $page->save();
        Notification::fake();
        \App\Support\Reviews::ask($writer, $page, '');
        Notification::assertNotSentTo($blogEditor, \App\Notifications\ReviewNews::class);
        Notification::assertSentTo($this->admin, \App\Notifications\ReviewNews::class);
    }

    public function test_stacy_keeps_to_the_card_too(): void
    {
        $this->card('Blog editor', 'editor', ['posts' => 'publish', 'pages' => 'none']);
        $user = User::factory()->create(['role' => 'blog_editor']);
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $tools = new Tools($user);

        $read = json_decode($tools->run(new ToolCall('t1', 'read_entry', ['entry_id' => $about->id()])), true);
        $this->assertStringContainsString('doesn’t open', $read['error']);

        $draft = json_decode($tools->run(new ToolCall('t2', 'propose_draft', ['collection' => 'pages', 'title' => 'New page'])), true);
        $this->assertStringContainsString('doesn’t open', $draft['error']);

        $search = json_decode($tools->run(new ToolCall('t3', 'search_content', ['query' => ''])), true);
        $this->assertNotEmpty($search['results']);
        $this->assertStringNotContainsString($about->id(), json_encode($search));

        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $this->assertTrue($tools->canPublish($post));
        $this->assertFalse($tools->canPublish($about));
    }

    public function test_an_admin_tries_the_book_as_a_card_then_comes_back(): void
    {
        $this->card('Guest writer', 'writer', ['posts' => 'write', 'pages' => 'none']);
        $role = Role::where('key', 'guest_writer')->first();

        Livewire::actingAs($this->admin)->test(People::class)
            ->call('editCard', $role->id)
            ->assertSee('See the book as this card')
            ->call('viewAs', 'guest_writer')
            ->assertRedirect(route('admin.contents'));
        $this->assertSame('guest_writer', session(User::VIEW_AS));

        $this->get(route('admin.contents'))->assertOk()->assertSee('Seeing the book as')->assertSee('Guest writer');
        $this->get(route('admin.entries', 'pages'))->assertForbidden();
        $this->get(route('admin.settings.health'))->assertForbidden();
        $this->assertFalse($this->admin->fresh()->isAdmin()); // while trying it out
        $this->assertSame('admin', $this->admin->fresh()->role); // nothing stored changes

        $this->post(route('admin.view-as.stop'))->assertRedirect(route('admin.people'));
        $this->get(route('admin.entries', 'pages'))->assertOk();
        $this->get(route('admin.contents'))->assertDontSee('Seeing the book as');

        // Only admins can try cards, and never "admin" itself.
        $writer = User::factory()->create(['role' => 'writer']);
        Livewire::actingAs($writer)->test(People::class)->call('viewAs', 'guest_writer')->assertForbidden();
        Livewire::actingAs($this->admin)->test(People::class)->call('viewAs', 'admin')->assertNotFound();

        // A writer with the key in their session is still a writer.
        $this->actingAs($writer)->withSession([User::VIEW_AS => 'editor'])->get(route('admin.menus'))->assertForbidden();
    }

    public function test_tearing_up_a_card_sends_holders_back_to_its_base(): void
    {
        $role = $this->card('Section editor', 'editor', ['posts' => 'publish', 'pages' => 'none']);
        $holder = User::factory()->create(['role' => 'section_editor']);

        Livewire::actingAs($this->admin)->test(People::class)
            ->call('editCard', $role->id)
            ->call('deleteCard')
            ->assertDispatched('stamp', text: 'Card torn up. 1 person went back to being an editor.');

        $this->assertSame('editor', $holder->fresh()->role);
        $this->assertDatabaseMissing('roles', ['key' => 'section_editor']);

        // A card that vanished some other way: they act like a writer, safely.
        $odd = User::factory()->create(['role' => 'no_such_card']);
        $this->assertSame('writer', $odd->level());
        $this->assertTrue($odd->canOpen('pages'));
        $this->assertFalse($odd->canPublishIn('posts'));
    }
}
