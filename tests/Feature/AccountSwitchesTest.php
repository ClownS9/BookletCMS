<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Contents;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\NewComment;
use App\Notifications\WeeklyLetter as Letter;
use App\Stacy\Nudges;
use App\Stacy\WeeklyLetter;
use App\Support\CommentAlerts;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The three switches in Your account do what they say:
 * comment emails, Stacy's suggestions, and the weekly letter.
 */
class AccountSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->writer = User::factory()->create(['role' => 'writer', 'name' => 'Hana Rahman']);
    }

    protected function entryBy(User $user)
    {
        $entry = Booklet::entries()->all()->first();
        $entry->set('author', $user->id)->save();

        return $entry;
    }

    protected function commentOn($entry): Comment
    {
        return Comment::create(['entry_id' => $entry->id(), 'author_name' => 'A reader', 'author_email' => 'r@example.com', 'body' => 'Lovely piece!', 'status' => 'waiting']);
    }

    public function test_a_new_comment_emails_the_author_once_in_a_while(): void
    {
        Notification::fake();
        $entry = $this->entryBy($this->writer);

        $this->assertSame(1, CommentAlerts::deliver($this->commentOn($entry)));
        $this->assertSame(0, CommentAlerts::deliver($this->commentOn($entry)), 'not again within ten minutes');

        Notification::assertSentTo($this->writer, NewComment::class, fn ($n) => $n->entry->id() === $entry->id());
        Notification::assertSentToTimes($this->writer, NewComment::class, 1);
    }

    public function test_no_comment_email_when_switched_off(): void
    {
        Notification::fake();
        $this->writer->update(['preferences' => ['notify_comments' => false]]);
        $entry = $this->entryBy($this->writer);

        $this->assertSame(0, CommentAlerts::deliver($this->commentOn($entry)));
        Notification::assertNothingSent();
    }

    public function test_the_weekly_letter_goes_only_to_people_who_want_it_once_a_week(): void
    {
        Notification::fake();
        $this->writer->update(['preferences' => ['weekly_summary' => true]]);
        $quiet = User::factory()->create(['role' => 'editor']);

        $this->assertSame(1, WeeklyLetter::sendAll());
        $this->assertSame(0, WeeklyLetter::sendAll(), 'already had this week’s');

        Notification::assertSentTo($this->writer, Letter::class, fn ($n) => str_starts_with($n->week['hello'], 'Good morning, Hana'));
        Notification::assertNotSentTo($quiet, Letter::class);

        $this->artisan('booklet:weekly-letter --force')->assertSuccessful();
        Notification::assertSentToTimes($this->writer, Letter::class, 2);
    }

    public function test_stacy_notices_a_quiet_draft_and_not_now_hides_it(): void
    {
        $draft = Booklet::entries()->make(['collection' => 'posts', 'slug' => 'half-a-thought', 'published' => false], ['title' => 'Half a thought']);
        $draft->set('author', $this->writer->id)->save();
        // Make it old.
        $this->travel(Nudges::QUIET_DAYS + 3)->days();

        $nudges = Nudges::for($this->writer);
        $quiet = collect($nudges)->firstWhere('key', 'quiet-'.$draft->id());
        $this->assertNotNull($quiet);
        $this->assertStringContainsString('Half a thought', $quiet['text']);

        $this->actingAs($this->writer);
        Livewire::test(Contents::class)->assertSee('Stacy noticed')->call('notNow', $quiet['key']);
        $this->assertNull(collect(Nudges::for($this->writer))->firstWhere('key', $quiet['key']));
    }

    public function test_suggestions_switched_off_means_none(): void
    {
        $this->writer->update(['preferences' => ['stacy_suggestions' => false]]);
        $this->assertSame([], Nudges::for($this->writer));
    }
}
