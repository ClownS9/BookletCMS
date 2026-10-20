<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Comments as AdminComments;
use App\Livewire\Site\Comments;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\CommentReply;
use App\Support\ReplyAlerts;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Readers replying to each other, and hearing about replies by email.
 */
class CommentRepliesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $entryId;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'nur@example.com', 'name' => 'Nur']);
        $this->entryId = Booklet::entries()->findBySlug('posts', 'hello-from-booklet')->id();
        Notification::fake();
    }

    protected function note(array $values = []): Comment
    {
        return Comment::create($values + ['entry_id' => $this->entryId, 'author_name' => 'Hana', 'author_email' => 'hana@example.com', 'body' => 'Mine collapsed twice.', 'status' => 'approved', 'notify' => true]);
    }

    /** Emails go after the page has answered. */
    protected function afterResponse(): void
    {
        $this->app->terminate();
    }

    /** The reply emails that went to one address. */
    protected function sentTo(string $email): \Illuminate\Support\Collection
    {
        $out = collect();
        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byNotification) {
                foreach ($byNotification[CommentReply::class] ?? [] as $sent) {
                    if (array_key_exists($email, (array) ($sent['notifiable']->routes['mail'] ?? []))) {
                        $out->push($sent['notification']);
                    }
                }
            }
        }

        return $out;
    }

    public function test_a_reader_replies_and_the_note_writer_hears(): void
    {
        // Notes go up straight away on this site (no waiting for approval).
        Booklet::globals('site')->set('moderation', false)->save();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        $note = $this->note();

        Livewire::test(Comments::class, ['entryId' => $this->entryId])
            ->call('startReply', $note->id)
            ->assertSee('Reply to Hana')
            ->set('name', 'Kai')->set('email', 'kai@example.com')->set('notify', true)
            ->set('body', 'A springform tin, and don’t open the oven!')
            ->call('post')
            ->assertHasNoErrors()
            ->assertSet('replyTo', null);

        $reply = Comment::where('author_name', 'Kai')->first();
        $this->assertSame($note->id, $reply->parent_id);
        $this->assertTrue($reply->notify);
        $this->afterResponse();

        $mail = $this->sentTo('hana@example.com');
        $this->assertCount(1, $mail);
        $message = $mail->first()->toMail(new AnonymousNotifiable);
        $this->assertSame('Kai replied to your note on “Hello from Booklet”', $message->subject);
        $this->assertStringContainsString('#c-'.$reply->id, $message->actionUrl);
        $this->assertCount(0, $this->sentTo('kai@example.com')); // never the one who wrote

        // Nur replies from the admin: both Hana and Kai asked to hear.
        $this->actingAs($this->admin);
        Livewire::test(AdminComments::class)->call('open', $note->id)->set('reply', 'So glad it worked!')->call('sendReply')
            ->assertDispatched('stamp', text: 'Reply posted. They asked to hear about replies, so it’s on its way by email too.');
        $this->afterResponse();
        $this->assertCount(1, $this->sentTo('kai@example.com'));
        $this->assertSame('A new reply in the conversation on “Hello from Booklet”', $this->sentTo('kai@example.com')->first()->toMail(new AnonymousNotifiable)->subject);
        // Hana heard a moment ago, so this one waits (at most one email every ten minutes).
        $this->assertCount(1, $this->sentTo('hana@example.com'));
    }

    public function test_waiting_replies_reach_people_only_once_approved(): void
    {
        $note = $this->note();
        $reply = Comment::create(['entry_id' => $this->entryId, 'parent_id' => $note->id, 'author_name' => 'Spammy', 'author_email' => 'x@example.com', 'body' => 'Hmm', 'status' => 'waiting']);
        $this->afterResponse();
        $this->assertCount(0, $this->sentTo('hana@example.com'));

        // It's in the admin's waiting pile, with the note it answers.
        $this->actingAs($this->admin);
        $page = Livewire::test(AdminComments::class)
            ->assertSee('replied to Hana on')
            ->call('pick', $reply->id)
            ->assertSee('In reply to');
        $page->call('setChosenStatus', 'approved');
        $this->afterResponse();
        $this->assertCount(1, $this->sentTo('hana@example.com'));

        // Approving it again (or saving it) doesn't send twice.
        $reply->refresh()->update(['status' => 'approved', 'body' => 'Hmm!']);
        $this->afterResponse();
        $this->assertCount(1, $this->sentTo('hana@example.com'));
    }

    public function test_no_box_ticked_means_no_email(): void
    {
        $note = $this->note(['notify' => false]);
        Livewire::test(Comments::class, ['entryId' => $this->entryId])
            ->call('startReply', $note->id)
            ->set('name', 'Kai')->set('body', 'Lovely.')->set('notify', true) // ticked, but no email given
            ->call('post');
        $this->assertFalse(Comment::where('author_name', 'Kai')->first()->notify);
        $this->afterResponse();
        $this->assertCount(0, $this->sentTo('hana@example.com'));

        // Replying to a note that isn't approved (or isn't on this post) makes a new note instead.
        $hidden = $this->note(['status' => 'spam']);
        Livewire::test(Comments::class, ['entryId' => $this->entryId])
            ->call('startReply', $hidden->id)->assertSet('replyTo', null)
            ->set('replyTo', $hidden->id)->set('name', 'Kai')->set('body', 'Sneaky')->call('post');
        $this->assertNull(Comment::where('body', 'Sneaky')->first()->parent_id);
    }

    public function test_emails_carry_the_sites_name_not_the_frameworks(): void
    {
        config(['app.name' => 'Laravel', 'mail.from.name' => 'Laravel']);
        Booklet::globals('site')->set('site_name', 'Kopi & Kuih')->save();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        $note = $this->note();
        $reply = $this->note(['parent_id' => $note->id, 'author_name' => 'Kai', 'author_email' => 'kai@example.com', 'status' => 'waiting']);
        $html = (string) (new CommentReply($reply, $reply->entry(), true, ReplyAlerts::stopUrl($note, 'hana@example.com')))->toMail(new AnonymousNotifiable)->render();

        $site = 'Kopi & Kuih';
        $this->assertStringContainsString(e($site), $html);
        $this->assertStringNotContainsString('Laravel', $html);

        \App\Support\MailSettings::apply();
        $this->assertSame($site, config('mail.from.name'));

        // Laravel's own password reset email, too.
        $reset = (new \Illuminate\Auth\Notifications\ResetPassword('tok'))->toMail($this->admin);
        $this->assertSame('Choose a new password for Kopi & Kuih', $reset->subject);
        $this->assertStringNotContainsString('Laravel', (string) $reset->render());
    }

    public function test_stopping_the_emails_from_the_link(): void
    {
        $note = $this->note();
        $this->note(['parent_id' => $note->id, 'body' => 'Me again', 'status' => 'approved']); // Hana replied in her own conversation
        $url = ReplyAlerts::stopUrl($note, 'Hana@Example.com');
        $this->assertStringNotContainsString('hana', strtolower($url));

        // Opening it only asks (mail scanners follow links).
        $this->get($url)->assertOk()->assertSee('Stop the emails about this conversation?');
        $this->assertTrue($note->fresh()->notify);

        $this->post($url)->assertOk()->assertSee('No more emails about it');
        $this->assertFalse($note->fresh()->notify);
        $this->assertSame(0, Comment::where('notify', true)->count());

        // A changed link doesn't work; mail apps' one-click works without a page.
        $this->get($url.'x')->assertOk()->assertSee('That link doesn’t work');
        $other = $this->note(['author_email' => 'kai@example.com']);
        $this->post(ReplyAlerts::stopUrl($other, 'kai@example.com'), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertFalse($other->fresh()->notify);
    }
}
