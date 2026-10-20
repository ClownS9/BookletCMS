<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\Menus;
use App\Booklet\Support\PageTree;
use App\Livewire\Admin\Entries;
use App\Livewire\Admin\Menus as MenusScreen;
use App\Livewire\Admin\Taxonomies;
use App\Livewire\Auth\Setup;
use App\Models\StacyConversation;
use App\Models\User;
use App\Stacy\Chat;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The chapters added to match the prototype: the page tree, categories &
 * tags, site menus, first setup, visits, and Stacy's yes/no handling.
 */
class BookletChaptersTest extends TestCase
{
    use RefreshDatabase;

    protected function book(): User
    {
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);

        return User::factory()->create(['role' => 'admin']);
    }

    public function test_a_sub_page_lives_under_its_parent_address(): void
    {
        $this->book();
        $about = Booklet::entries()->findBySlug('pages', 'about');

        $team = Booklet::entries()->make(['collection' => 'pages', 'slug' => 'team'], ['title' => 'Team'])->save();
        PageTree::for('pages')->place($team->id(), $about->id())->save();

        $this->assertSame('/about/team', Booklet::entries()->find($team->id())->uri());
        $this->get('/about/team')->assertOk()->assertSee('Team')->assertSee('About');

        // Moving it back to the top changes its address too.
        PageTree::for('pages')->place($team->id())->save();
        $this->assertSame('/team', Booklet::entries()->find($team->id())->uri());
    }

    public function test_slugs_stay_unique_within_a_collection(): void
    {
        $this->book();

        $a = Booklet::entries()->make(['collection' => 'posts'], ['title' => 'Same title'])->save();
        $b = Booklet::entries()->make(['collection' => 'posts'], ['title' => 'Same title'])->save();

        $this->assertSame('same-title', $a->slug());
        $this->assertSame('same-title-2', $b->slug());
    }

    public function test_the_pages_chapter_toggles_the_site_menu(): void
    {
        $user = $this->book();
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $this->assertTrue(Menus::has('header', $about->id()));

        Livewire::actingAs($user)->test(Entries::class, ['collection' => 'pages'])
            ->set('selected', $about->id())
            ->call('toggleMenu')
            ->call('setTemplate', 'full_width');

        $this->assertFalse(Menus::has('header', $about->id()));
        $this->assertSame('full_width', Booklet::entries()->find($about->id())->get('template'));
    }

    public function test_renaming_a_tag_follows_it_onto_posts(): void
    {
        $user = $this->book();

        Livewire::actingAs($user)->test(Taxonomies::class)
            ->call('choose', 'tags', 'welcome')
            ->set('rename', 'Hello there')
            ->call('renameTerm')
            ->assertHasNoErrors();

        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $this->assertSame(['hello-there'], $post->get('tags'));
        $this->assertNull(Booklet::terms()->findBySlug('tags', 'welcome'));
    }

    public function test_menus_can_be_rearranged_and_saved(): void
    {
        $user = $this->book();

        Livewire::actingAs($user)->test(MenusScreen::class)
            ->call('remove', 0)
            ->set('linkLabel', 'Shop')
            ->set('linkUrl', 'https://shop.example.com')
            ->call('addLink')
            ->call('save');

        $items = Menus::items('header');
        $this->assertSame('Shop', end($items)['title']);
        $this->assertCount(4, $items);
    }

    public function test_writers_cannot_open_site_menus(): void
    {
        $this->book();
        $writer = User::factory()->create(['role' => 'writer']);

        $this->actingAs($writer)->get(route('admin.menus'))->assertForbidden();
    }

    public function test_a_brand_new_book_starts_with_setup(): void
    {
        $this->get('/login')->assertRedirect(route('setup'));

        Livewire::test(Setup::class)
            ->set('siteName', 'Rainy Days')
            ->call('next')
            ->set('name', 'Nur')
            ->set('email', 'nur@example.com')
            ->set('password', 'a-long-password')
            ->call('next')
            ->call('pickAccent', 'Mint')
            ->call('next')
            ->assertSet('step', 3);

        $this->assertSame('admin', User::firstWhere('email', 'nur@example.com')->role);
        $this->assertSame('Rainy Days', Booklet::globals('site')->get('site_name'));
        $this->assertSame('#86CDB9', Booklet::globals('site')->get('accent'));
        $this->get('/setup')->assertRedirect();
    }

    public function test_public_visits_are_counted_for_the_dashboard(): void
    {
        $this->book();
        auth()->logout();

        $this->get('/')->assertOk();
        $this->get('/about')->assertOk();

        $this->assertSame(2, (int) DB::table('site_visits')->value('views'));
    }

    public function test_a_typed_yes_confirms_stacys_proposal(): void
    {
        $user = $this->book();
        $draft = Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Waiting', 'author' => $user->id])->save();

        $conversation = StacyConversation::create(['user_id' => $user->id, 'title' => 'Publish']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Publish my draft']);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Publish “Waiting” now. Shall I go ahead?',
            'meta' => ['pending' => ['action' => 'publish', 'entry_id' => $draft->id(), 'summary' => 'Publish “Waiting” now.', 'button' => 'Yes']],
        ]);

        $reply = Chat::ask($user, $conversation, 'Yes please!');

        $this->assertSame('Done. It’s live.', $reply->content);
        $this->assertSame('published', Booklet::entries()->find($draft->id())->status());
        $this->assertNull(Chat::yesOrNo('Yes, but change the title first'));
    }

    public function test_take_me_to_pages_opens_pages_without_asking_the_ai(): void
    {
        $user = $this->book();

        $reply = Chat::ask($user, null, 'Bring me to pages');

        $this->assertSame('Taking you to Pages.', $reply->content);
        $this->assertSame(route('admin.entries', 'pages'), $reply->meta['navigate']['href']);
        $this->assertSame('media', \App\Stacy\Tools::placeFor('Stacy, open the media page please'));
        $this->assertNull(\App\Stacy\Tools::placeFor('show me my drafts'));
    }

    public function test_her_words_lose_markdown_links_and_emoji(): void
    {
        $this->assertSame(
            'Here’s the link to the Pages chapter where you can manage your pages. Let me know if you need anything!',
            Chat::tidy('Here’s the link to the **[Pages chapter](go_to_pages)** where you can manage your pages. Let me know if you need anything! 😊')
        );
    }
}
