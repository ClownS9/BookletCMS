<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Comments;
use App\Livewire\Admin\People;
use App\Models\Comment;
use App\Models\Role;
use App\Models\User;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * People and Comments as tables in the Dashboard layout, with a drawer for
 * one row; the Book layout keeps its two pages.
 */
class PeopleCommentsTablesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah']);
        YourBook::save($this->admin, ['layout' => 'dashboard']);
        $this->admin = $this->admin->fresh();
    }

    protected function note(string $name, string $body, string $status = 'waiting'): Comment
    {
        $post = Booklet::entries()->all()->first(fn ($e) => $e->collectionHandle() === 'posts');

        return Comment::create(['entry_id' => $post->id(), 'author_name' => $name, 'author_email' => strtolower($name).'@example.com', 'body' => $body, 'status' => $status]);
    }

    // Comments ----------------------------------------------------------------

    public function test_comments_are_a_table_with_a_drawer_to_read_and_reply(): void
    {
        $kai = $this->note('Kai', 'A springform tin, and don’t open the oven!');

        $this->actingAs($this->admin)->get(route('admin.comments'))->assertOk()
            ->assertSee('dash-table--comments', false)->assertSee('Kai')->assertDontSee('class="drawer"', false);

        Livewire::actingAs($this->admin)->test(Comments::class)
            ->assertSee('dash-table--comments', false)
            ->call('pick', $kai->id)
            ->assertSet('drawer', true)
            ->assertSee('Comment from Kai')
            ->set('reply', 'Thank you!')->call('sendReply')
            ->call('closeDrawer')->assertSet('drawer', false);

        $this->assertSame('approved', $kai->fresh()->status); // replying to a waiting note approves it
    }

    public function test_approving_and_spamming_from_a_row_and_many_at_once(): void
    {
        $a = $this->note('Ana', 'Lovely');
        $b = $this->note('Ben', 'Buy cheap watches');
        $c = $this->note('Cik', 'Also lovely');

        $table = Livewire::actingAs($this->admin)->test(Comments::class)->call('setStatusFor', $a->id, 'approved');
        $this->assertSame('approved', $a->fresh()->status);

        $table->set('ticked', [$b->id])->call('setStatusTicked', 'spam')->assertSet('ticked', []);
        $this->assertSame('spam', $b->fresh()->status);

        $table->call('binOne', $c->id);
        $this->assertNull(Comment::find($c->id));
    }

    public function test_searching_comments(): void
    {
        $this->note('Ana', 'The pandan one was best');
        $this->note('Ben', 'Too sweet');

        Livewire::actingAs($this->admin)->test(Comments::class)
            ->set('search', 'pandan')->assertSee('The pandan one was best')->assertDontSee('Too sweet')
            ->set('search', 'ben@')->assertSee('Too sweet');
    }

    public function test_opening_a_comment_by_link_opens_its_drawer(): void
    {
        $kai = $this->note('Kai', 'Hello');
        Livewire::withQueryParams(['comment' => $kai->id])->actingAs($this->admin)->test(Comments::class)
            ->assertSet('drawer', true)->assertSee('Comment from Kai');
    }

    // People ------------------------------------------------------------------

    public function test_people_are_a_table_with_roles_below(): void
    {
        User::factory()->create(['role' => 'editor', 'name' => 'Hana Rahman', 'email' => 'hana@example.com']);

        $this->actingAs($this->admin)->get(route('admin.people'))->assertOk()
            ->assertSee('dash-table--people', false)->assertSee('Hana Rahman')->assertSee('hana@example.com')
            ->assertSee('Roles')->assertSee('Built in')->assertDontSee('class="drawer"', false);
    }

    public function test_filtering_and_searching_people(): void
    {
        User::factory()->create(['role' => 'editor', 'name' => 'Hana Rahman']);
        User::factory()->create(['role' => 'writer', 'name' => 'Mei Ling']);

        Livewire::actingAs($this->admin)->test(People::class)
            ->set('only', 'editor')->assertSee('Hana Rahman')->assertDontSee('Mei Ling')
            ->call('clearFilters')->set('search', 'mei')->assertSee('Mei Ling')->assertDontSee('Hana Rahman')
            ->set('only', 'nonsense')->assertSet('only', '');
    }

    public function test_a_person_opens_in_a_drawer_to_change_their_role(): void
    {
        $hana = User::factory()->create(['role' => 'writer', 'name' => 'Hana Rahman']);

        Livewire::actingAs($this->admin)->test(People::class)
            ->assertSet('drawer', false)
            ->call('pick', $hana->id)->assertSet('drawer', true)->assertSee('Save role')
            ->set('role', 'editor')->call('saveRole');
        $this->assertSame('editor', $hana->fresh()->role);
    }

    public function test_removing_from_a_row_and_roles_open_in_the_drawer(): void
    {
        $hana = User::factory()->create(['role' => 'writer']);
        $card = Role::create(['key' => 'guest', 'name' => 'Guest writer', 'base' => 'writer', 'tone' => 'sky']);

        Livewire::actingAs($this->admin)->test(People::class)
            ->call('removeOne', $hana->id)->assertSet('drawer', false)
            ->call('editCard', $card->id)->assertSet('drawer', true)->assertSee('Guest writer')
            ->call('closeCard')->assertSet('drawer', false)->assertSet('card', null);
        $this->assertNull($hana->fresh());
    }

    public function test_the_book_layout_keeps_its_pages(): void
    {
        YourBook::save($this->admin, ['layout' => 'book']);
        $this->actingAs($this->admin->fresh())->get(route('admin.people'))->assertOk()->assertDontSee('dash-table--people', false)->assertSee('Library cards');
        $this->actingAs($this->admin->fresh())->get(route('admin.comments'))->assertOk()->assertDontSee('dash-table--comments', false);
    }
}
