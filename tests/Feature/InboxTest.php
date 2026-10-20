<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Inbox as InboxPage;
use App\Livewire\Site\ContactForm;
use App\Models\User;
use App\Notifications\MessageReply;
use App\Notifications\NewMessage;
use App\Support\Inbox;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The contact form on the Contact page, and the Inbox where its messages land.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah', 'email' => 'nur@example.com']);
    }

    protected function write(array $values = [], int $secondsAgo = 10, string $website = '')
    {
        $this->travel(-$secondsAgo)->seconds();
        $form = Livewire::test(ContactForm::class, ['page' => '/contact']);
        $this->travelBack();

        return $form->set('website', $website)->set('values', $values + ['name' => 'Siti', 'email' => 'Siti@Example.com', 'message' => "Do you take orders for weddings?\n\nAbout 200 pieces."])->call('send');
    }

    public function test_the_contact_page_shows_the_form(): void
    {
        $this->get('/contact')->assertOk()->assertSee('Write to us')->assertSee('Send message');

        Inbox::setOpen(false);
        $this->get('/contact')->assertOk()->assertDontSee('Write to us');
        $this->get('/about')->assertDontSee('Write to us');
    }

    public function test_a_message_lands_in_the_inbox_and_the_team_hears(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $writer = User::factory()->create(['role' => 'writer']);

        $this->write()->assertHasNoErrors()->assertSet('sent', true)->assertSee('on its way');

        $m = Inbox::all()->first();
        $this->assertSame('new', Inbox::status($m));
        $this->assertSame(['Siti', 'siti@example.com'], Inbox::sender($m));
        $this->assertSame('/contact', $m->get('_page'));
        $this->assertSame(1, Inbox::newCount());

        Notification::assertSentTo([$this->admin, $editor], NewMessage::class);
        Notification::assertNotSentTo($writer, NewMessage::class);

        $mail = (new NewMessage($m))->toMail($this->admin);
        $this->assertSame([['siti@example.com', 'Siti']], $mail->replyTo, 'Reply in the mail app goes to Siti');
        $this->assertStringContainsString('Siti wrote: Do you take orders', $mail->subject);
    }

    public function test_bots_get_nothing_and_quick_or_linky_messages_go_to_spam(): void
    {
        $this->write(website: 'http://spam.example')->assertSet('sent', true);
        $this->assertCount(0, Inbox::all(), 'the honeypot keeps nothing');

        $this->write([], secondsAgo: 0);
        $this->write(['message' => 'Cheap pills http://a.example http://b.example http://c.example']);

        $this->assertSame(['spam', 'spam'], Inbox::all()->map(fn ($m) => Inbox::status($m))->all());
        Notification::assertNothingSent();
    }

    public function test_bad_details_and_too_many_messages_are_stopped(): void
    {
        $this->write(['email' => 'not an email', 'message' => ''])->assertHasErrors(['values.email', 'values.message']);
        $this->write(['name' => 'Visit http://x.example'])->assertHasErrors('values.name');

        $this->write(); $this->write(); $this->write();
        $this->write()->assertHasErrors('values.message');
        $this->assertCount(3, Inbox::all());
    }

    public function test_reading_writing_back_and_tidying(): void
    {
        $this->write();
        $m = Inbox::all()->first();

        $this->actingAs(User::factory()->create(['role' => 'writer']))->get(route('admin.inbox'))->assertForbidden();

        $page = Livewire::actingAs($this->admin)->test(InboxPage::class)
            ->assertSee('Siti')->call('pick', $m->id())->assertSee('About 200 pieces.');
        $this->assertSame('read', Inbox::status(Inbox::find($m->id())));

        $page->call('sendReply')->assertHasErrors('reply');
        $page->set('reply', "Yes, we do!\n\nWhich date?")->call('sendReply')->assertHasNoErrors()->assertSee('Yes, we do!');

        Notification::assertSentTo(new AnonymousNotifiable, MessageReply::class, function ($n, $channels, $notifiable) {
            $mail = $n->toMail($notifiable);

            return $notifiable->routes['mail'] === ['siti@example.com' => 'Siti']
                && $mail->replyTo === [['nur@example.com', 'Nur Aisyah']]
                && $mail->greeting === 'Hello Siti,';
        });

        $page->call('setChosenStatus', 'archived');
        $this->assertSame(['inbox' => 0, 'archived' => 1], array_intersect_key(Inbox::counts(), ['inbox' => 0, 'archived' => 0]));

        // Spam is forgotten after a month.
        Inbox::receive(['name' => 'Bot', 'email' => 'b@example.com', 'message' => 'x'], '', spam: true);
        $this->travel(Inbox::SPAM_DAYS + 1)->days();
        $this->assertSame(1, Inbox::tidy());
        $this->assertCount(1, Inbox::all());
    }

    public function test_opening_from_the_email_marks_it_read(): void
    {
        $this->write();
        $m = Inbox::all()->first();

        $this->actingAs($this->admin)->get(route('admin.inbox', ['message' => $m->id()]))->assertOk()->assertSee('About 200 pieces.');
        $this->assertSame('read', Inbox::status(Inbox::find($m->id())));
    }
}
