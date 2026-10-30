<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Readers as ReadersPage;
use App\Livewire\Site\ReaderJoin;
use App\Livewire\Site\ReaderPage;
use App\Livewire\Site\ReaderSignIn;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reader;
use App\Models\User;
use App\Notifications\ReaderAlreadyHere;
use App\Notifications\ReaderConfirmEmail;
use App\Notifications\ReaderInvitation;
use App\Notifications\ReaderLetIn;
use App\Support\ContentApi;
use App\Support\Members;
use App\Support\ReaderAccounts;
use App\Support\Readers;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Readers: accounts on the public site, groups, and members-only posts,
 * pages and sections. A reader can never open the admin, and what's for
 * members never leaks through search, the feed or the content API.
 */
class ReadersTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected string $password = 'velvet-harbour-quince-42';

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        Readers::forget();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin', 'name' => 'Nur']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Readers::forget();
        parent::tearDown();
    }

    /** Signed in as a reader, the way a real request is: the book's own guard stays the default. */
    protected function asReader(Reader $reader): static
    {
        Auth::guard('reader')->login($reader);

        return $this;
    }

    protected function joining(string $how, array $more = []): void
    {
        Readers::save(['joining' => $how] + $more);
    }

    protected function reader(string $state = 'active', array $more = []): Reader
    {
        return Reader::create($more + [
            'name' => 'Aminah Rahman', 'email' => 'aminah@example.com', 'password' => $this->password,
            'state' => $state, 'email_verified_at' => $state === 'unconfirmed' ? null : now(),
        ]);
    }

    protected function livePost(array $data = [], string $title = 'The pandan secret')
    {
        return Booklet::entries()->make(
            ['collection' => 'posts', 'published' => true, 'date' => '2026-03-04 09:00:00'],
            ['title' => $title, 'content' => "The first lines everyone sees. ".str_repeat('Pandan leaves, eggs, flour and a slow oven make a tall, soft cake. ', 6)."\n\nThe secret is a spoonful of coconut cream folded in last.", 'author' => $this->nur->id] + $data,
        )->save();
    }

    public function test_nobody_can_join_until_the_book_says_so(): void
    {
        $this->get('/members/join')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee(route('readers.sign-in'));

        $this->joining('open');
        $this->get('/members/join')->assertOk()->assertSee('Make my account')->assertHeader('X-Robots-Tag', 'noindex');
        $this->get('/')->assertOk()->assertSee(route('readers.sign-in'));
    }

    public function test_joining_confirming_and_being_let_in(): void
    {
        $this->joining('approval');

        Livewire::test(ReaderJoin::class)
            ->set('name', 'Aminah Rahman')->set('email', 'Aminah@Example.com')->set('password', $this->password)
            ->call('join')->assertHasNoErrors()->assertSet('done', true);

        $reader = Reader::query()->where('email', 'aminah@example.com')->firstOrFail();
        $this->assertSame('unconfirmed', $reader->state);
        $this->assertTrue(Auth::guard('reader')->check(), 'signed in to see “check your inbox”');
        Notification::assertSentTo($reader, ReaderConfirmEmail::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        // The button in the email: confirmed, and (with approval) waiting for the book.
        $this->get($url)->assertRedirect(route('readers.home'));
        $this->assertSame('waiting', $reader->fresh()->state);
        $this->app['auth']->forgetGuards(); // the next request reads the account afresh
        $this->get(route('readers.home'))->assertOk()->assertSee('Waiting to be let in');

        // A link that's been tampered with does nothing.
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();

        Livewire::actingAs($this->nur)->test(ReadersPage::class)->call('letIn', $reader->id);
        $this->assertSame('active', $reader->fresh()->state);
        Notification::assertSentTo($reader, ReaderLetIn::class);
    }

    public function test_an_address_that_has_an_account_learns_nothing_new_on_the_site(): void
    {
        $this->joining('open');
        $this->reader();

        Livewire::test(ReaderJoin::class)
            ->set('name', 'Someone')->set('email', 'aminah@example.com')->set('password', 'another-long-pass-phrase-9')
            ->call('join')->assertHasNoErrors()->assertSet('done', true);

        $this->assertSame(1, Reader::count());
        Notification::assertSentTo(Reader::first(), ReaderAlreadyHere::class);
    }

    public function test_weak_passwords_and_missing_answers_are_turned_back(): void
    {
        $this->joining('open', ['questions' => [['key' => 'town', 'label' => 'Where are you from?', 'type' => 'choice', 'options' => ['Brunei', 'Miri'], 'required' => true]]]);

        Livewire::test(ReaderJoin::class)
            ->set('name', 'Aminah')->set('email', 'a@example.com')->set('password', 'password1')
            ->call('join')->assertHasErrors(['password', 'answers.town']);

        Livewire::test(ReaderJoin::class)
            ->set('name', 'Aminah')->set('email', 'a@example.com')->set('password', $this->password)->set('answers.town', 'Tokyo')
            ->call('join')->assertHasErrors(['answers.town']);

        Livewire::test(ReaderJoin::class)
            ->set('name', 'Aminah')->set('email', 'a@example.com')->set('password', $this->password)->set('answers.town', 'Miri')
            ->call('join')->assertHasNoErrors();
        $this->assertSame(['town' => 'Miri'], Reader::first()->answers);
    }

    public function test_invitation_only_needs_an_invitation_which_lets_them_straight_in(): void
    {
        $this->joining('invite');
        $club = Group::create(['name' => 'Kuih Club', 'joining' => 'closed']);

        Livewire::test(ReaderJoin::class)->assertSee('Joining is by invitation')->call('join');
        $this->assertSame(0, Reader::count());

        $url = ReaderAccounts::invite('siti@example.com', 'Siti', ['kuih-club'], $this->nur);
        Notification::assertSentOnDemand(ReaderInvitation::class);

        // The signed link opens the page with their address; a changed link doesn't.
        $this->get($url)->assertOk()->assertSee('siti@example.com');
        $this->get(str_replace('siti%40', 'eve%40', $url))->assertOk()->assertSee('Joining is by invitation')->assertDontSee('eve@example.com');

        // The address is the invitation's, whatever is typed.
        Livewire::test(ReaderJoin::class, ['invite' => ['email' => 'siti@example.com', 'name' => 'Siti', 'groups' => ['kuih-club'], 'by' => $this->nur->id]])
            ->assertSet('email', 'siti@example.com')
            ->set('email', 'someone-else@example.com')->set('password', $this->password)
            ->call('join')->assertHasNoErrors()->assertRedirect();

        $siti = Reader::query()->where('email', 'siti@example.com')->firstOrFail();
        $this->assertSame('active', $siti->state);
        $this->assertSame($this->nur->id, $siti->invited_by);
        $this->assertSame(['kuih-club'], $siti->groupHandles());
        $this->assertSame(1, $club->members()->count());
    }

    public function test_signing_in_and_what_a_reader_can_never_open(): void
    {
        $this->joining('open');
        $reader = $this->reader();

        Livewire::test(ReaderSignIn::class)->set('email', 'aminah@example.com')->set('password', 'wrong-one')
            ->call('signIn')->assertHasErrors('email');
        $this->assertFalse(Auth::guard('reader')->check());

        Livewire::test(ReaderSignIn::class)->set('email', 'AMINAH@example.com')->set('password', $this->password)
            ->call('signIn')->assertHasNoErrors()->assertRedirect(route('readers.home'));
        $this->assertTrue(Auth::guard('reader')->check());

        // Signed in as a reader is not signed in to the book.
        $this->asReader($reader);
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/admin/people')->assertRedirect('/login');
        $this->assertNull(request()->user());

        // Blocked: the right password no longer works.
        Auth::guard('reader')->logout();
        $reader->forceFill(['state' => 'blocked'])->save();
        Livewire::test(ReaderSignIn::class)->set('email', 'aminah@example.com')->set('password', $this->password)
            ->call('signIn')->assertHasErrors('email');
    }

    public function test_where_to_go_after_signing_in_stays_on_this_site(): void
    {
        $this->assertNull(Readers::safeBack('https://evil.example/steal'));
        $this->assertNull(Readers::safeBack('//evil.example'));
        $this->assertNull(Readers::safeBack('/admin/people'));
        $this->assertSame('/blog/x', Readers::safeBack('/blog/x'));
    }

    public function test_members_only_posts_show_a_door_to_everyone_else_and_never_the_rest(): void
    {
        $this->joining('open');
        $post = $this->livePost([Members::WHO => 'readers']);

        $this->get($post->uri())->assertOk()
            ->assertSee('This one is for members')->assertSee('The first lines everyone sees.')
            ->assertDontSee('coconut cream')->assertSee(route('readers.sign-in').'?back=', false);

        // Not in search, the feed or the content API.
        $this->get('/search?q=coconut')->assertOk()->assertSee('Nothing found for', false);
        $this->get('/search?q=pandan')->assertOk()->assertSee('/blog/the-pandan-secret', false)->assertDontSee('coconut cream');
        $this->get('/feed.xml')->assertOk()->assertDontSee('coconut cream')->assertSee('For members: read it on the site');
        $api = ContentApi::entry($post, true);
        $this->assertArrayNotHasKey('content', $api);
        $this->assertSame('readers', $api['members_only']['open_to']);

        // A reader who's in: all of it. One still waiting: the door.
        $this->asReader($this->reader())->get($post->uri())->assertOk()->assertSee('coconut cream');
        $waiting = $this->reader('waiting', ['email' => 'w@example.com']);
        $this->asReader($waiting)->get($post->uri())->assertOk()->assertDontSee('coconut cream')->assertSee('Nearly there');

        // People from the book read everything.
        Auth::guard('reader')->logout();
        $this->actingAs($this->nur)->get($post->uri())->assertOk()->assertSee('coconut cream');
    }

    public function test_posts_for_some_groups_open_only_to_those_groups(): void
    {
        $this->joining('open');
        $club = Group::create(['name' => 'Kuih Club', 'joining' => 'open']);
        Group::create(['name' => 'Staff', 'joining' => 'closed']);
        $post = $this->livePost([Members::WHO => 'groups', Members::GROUPS => ['kuih-club']]);
        $reader = $this->reader();

        $this->asReader($reader)->get($post->uri())->assertOk()
            ->assertDontSee('coconut cream')->assertSee('This one is for Kuih Club');

        // Joining an open group from their own page opens it.
        $this->asReader($reader); Livewire::test(ReaderPage::class)->call('joinGroup', $club->id)->assertSee('You’re in Kuih Club.');
        \App\Support\Remember::forget();
        $this->asReader($reader)->get($post->uri())->assertOk()->assertSee('coconut cream');

        // A closed group can't be joined from the site.
        $staff = Group::query()->where('handle', 'staff')->first();
        $this->asReader($reader); Livewire::test(ReaderPage::class)->call('joinGroup', $staff->id);
        $this->assertFalse(GroupMember::query()->where('group_id', $staff->id)->exists());
    }

    public function test_a_groups_invitation_link_puts_people_in_it(): void
    {
        $this->joining('invite');
        $club = Group::create(['name' => 'Kuih Club', 'joining' => 'invite']);
        $token = $club->inviteToken();

        $this->get(route('readers.group-invite', ['kuih-club', 'x'.str_repeat('a', 30)]))->assertNotFound();
        $this->get(route('readers.group-invite', ['kuih-club', $token]))->assertOk()->assertSee('Join Kuih Club');

        // Held in the session: the join page opens, even with joining by invitation.
        Livewire::test(ReaderJoin::class)
            ->set('name', 'Siti')->set('email', 'siti@example.com')->set('password', $this->password)
            ->call('join')->assertHasNoErrors();
        $siti = Reader::query()->where('email', 'siti@example.com')->firstOrFail();
        $this->assertSame(['kuih-club'], $siti->groupHandles());

        // Once the group stops taking people by link, the old link stops working.
        $club->update(['joining' => 'closed']);
        $this->get(route('readers.group-invite', ['kuih-club', $token]))->assertNotFound();
    }

    public function test_taking_a_question_off_the_sign_up_page_really_takes_it_off(): void
    {
        Readers::save(['questions' => [['key' => 'a', 'label' => 'Where are you from?', 'type' => 'text'], ['key' => 'b', 'label' => 'Favourite kuih?', 'type' => 'text']]]);
        Readers::save(['questions' => [['key' => 'a', 'label' => 'Where are you from?', 'type' => 'text']]]);
        Readers::forget();
        $this->assertCount(1, Readers::questions());
    }

    public function test_group_names_are_their_own_and_readers_emails_speak_the_sites_language(): void
    {
        Group::create(['name' => 'Kuih Club', 'joining' => 'open']);
        $this->actingAs($this->nur);
        Livewire::test(ReadersPage::class)->call('newGroup')->set('groupName', 'Kuih Club')->call('saveGroup')->assertHasErrors('groupName');
        $this->assertSame(1, Group::query()->count());

        $reader = $this->reader();
        $this->assertSame(app(\App\Booklet\Site::class)->language(), $reader->preferredLocale());
    }

    public function test_page_sections_can_be_for_readers_or_for_people_not_signed_in(): void
    {
        $this->assertTrue(Members::seesSection('all'));
        $this->assertTrue(Members::seesSection('guests'));
        $this->assertFalse(Members::seesSection('readers'));

        $this->asReader($this->reader());
        $this->assertTrue(Members::seesSection('readers'));
        $this->assertFalse(Members::seesSection('guests'));

        $looks = app(\App\PageBuilder\Builder::class)->looks();
        $this->assertArrayHasKey('who', $looks);
    }

    public function test_the_admin_page_and_who_may_use_it(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($writer)->get(route('admin.readers'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.readers'))->assertOk()->assertSee('Readers & groups')->assertDontSee('How people join');
        $this->actingAs($this->nur)->get(route('admin.readers'))->assertOk()->assertSee('How people join');

        Livewire::actingAs($editor)->test(ReadersPage::class)->call('saveJoining')->assertForbidden();

        Livewire::actingAs($this->nur)->test(ReadersPage::class)
            ->set('tab', 'joining')->set('settings.joining', 'approval')
            ->call('addQuestion')->set('questions.0.label', 'Favourite kuih?')->set('questions.0.type', 'text')
            ->call('saveJoining')->assertHasNoErrors();
        Readers::forget();
        $this->assertSame('approval', Readers::joining());
        $this->assertSame('favourite_kuih', Readers::questions()[0]['key']);

        // A group, its invitation link, and someone from the book in it.
        Livewire::actingAs($this->nur)->test(ReadersPage::class)
            ->call('newGroup')->set('groupName', 'Kuih Club')->set('groupJoining', 'invite')->call('saveGroup')->assertHasNoErrors()
            ->call('addMember', 'user', $editor->id);
        $club = Group::query()->where('handle', 'kuih-club')->firstOrFail();
        $this->assertNotNull($club->invite_token);
        $this->assertTrue(GroupMember::query()->where(['group_id' => $club->id, 'member_type' => 'user', 'member_id' => $editor->id])->exists());
    }

    public function test_the_entry_editor_chooses_who_can_read_it(): void
    {
        Group::create(['name' => 'Kuih Club', 'joining' => 'open']);
        $post = $this->livePost();

        Livewire::actingAs($this->nur)->test(\App\Livewire\Admin\EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->assertSee('Who can read it')
            ->set('openTo', 'groups')->set('openToGroups', ['kuih-club', 'no-such-group'])
            ->call('save')->assertHasNoErrors();

        Booklet::flush();
        $saved = Booklet::entries()->find($post->id());
        $this->assertSame(['who' => 'groups', 'groups' => ['kuih-club']], Members::rule($saved));
    }

    public function test_signed_in_readers_never_get_or_make_a_kept_copy_of_a_page(): void
    {
        $this->asReader($this->reader());
        $request = \Illuminate\Http\Request::create('/blog');
        $request->setLaravelSession(app('session.store'));
        $this->assertFalse(\App\Support\PageCache::cacheable($request));
    }
}
