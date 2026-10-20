<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Subscribers;
use App\Livewire\Site\Subscribe;
use App\Models\PostEmail;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\ConfirmSubscription;
use App\Notifications\NewPostEmail;
use App\Support\Newsletter;
use App\Support\ShareImage;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * New posts by email (sign up, confirm, receive, leave) and the share cards.
 */
class NewsletterAndShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        User::factory()->create(['role' => 'admin']); // the site is open (past First setup)
        Notification::fake();
    }

    protected function publish(string $title, int $minutesAgo): \App\Booklet\Data\Entry
    {
        return Booklet::entries()->make(
            ['collection' => 'posts', 'published' => true, 'date' => now()->subMinutes($minutesAgo)->toDateTimeString()],
            ['title' => $title, 'excerpt' => 'A few words about '.$title.'.', 'content' => 'Words.'],
        )->save();
    }

    public function test_signing_up_needs_a_confirmation(): void
    {
        Livewire::test(Subscribe::class)->set('email', 'Reader@Example.com')->call('subscribe')->assertSet('done', 'sent');

        $subscriber = Subscriber::firstWhere('email', 'reader@example.com');
        $this->assertFalse($subscriber->isActive(), 'not on the list until they say yes');
        Notification::assertSentTo($subscriber, ConfirmSubscription::class);

        $this->get(route('site.subscribe.confirm', $subscriber->token))->assertOk()->assertSee('Thank you');
        $this->assertTrue($subscriber->fresh()->isActive());

        Livewire::test(Subscribe::class)->set('email', 'reader@example.com')->call('subscribe')->assertSet('done', 'already');
        $this->get(route('site.subscribe.confirm', str_repeat('x', 48)))->assertOk()->assertSee('expired');
    }

    public function test_bots_and_bad_addresses_get_nowhere(): void
    {
        Livewire::test(Subscribe::class)->set('email', 'bot@example.com')->set('website', 'http://spam')->call('subscribe');
        $this->assertSame(0, Subscriber::count());

        Livewire::test(Subscribe::class)->set('email', 'not an email')->call('subscribe')->assertHasErrors('email');
    }

    public function test_new_posts_go_out_once_after_a_pause(): void
    {
        Newsletter::since(); // switched on now: older posts never go out
        $reader = Subscriber::create(['email' => 'a@example.com', 'confirmed_at' => now()]);
        Subscriber::create(['email' => 'waiting@example.com']);
        Subscriber::create(['email' => 'gone@example.com', 'confirmed_at' => now()->subDay(), 'unsubscribed_at' => now()]);

        $this->travel(1)->minutes();
        $fresh = $this->publish('Just now', 1);
        $ready = $this->publish('Ready', 0);
        $this->travel(20)->minutes();

        $this->assertSame(2, Newsletter::sendDue(), 'both are past the pause now, and only the one confirmed reader gets them');
        Notification::assertSentToTimes($reader, NewPostEmail::class, 2);
        Notification::assertNotSentTo(Subscriber::firstWhere('email', 'waiting@example.com'), NewPostEmail::class);
        Notification::assertNotSentTo(Subscriber::firstWhere('email', 'gone@example.com'), NewPostEmail::class);

        $this->assertSame(0, Newsletter::sendDue(), 'never twice');
        $this->assertSame(2, PostEmail::count());
        $this->assertSame(2, $reader->fresh()->emails_sent);
    }

    public function test_old_posts_and_posts_marked_no_email_stay_put(): void
    {
        $this->publish('Before switching on', 60 * 24);
        Newsletter::since();
        Subscriber::create(['email' => 'a@example.com', 'confirmed_at' => now()]);
        $quiet = $this->publish('Quiet one', 0);
        $quiet->set('no_email', true)->save();
        $this->travel(20)->minutes();

        $this->assertSame(0, Newsletter::sendDue());
        Notification::assertNothingSent();
    }

    public function test_one_click_unsubscribe_from_a_mail_app(): void
    {
        $reader = Subscriber::create(['email' => 'a@example.com', 'confirmed_at' => now()]);

        $this->get(route('site.unsubscribe', $reader->token))->assertOk()->assertSee('Stop the emails?');
        $this->assertTrue($reader->fresh()->isActive(), 'opening the link alone changes nothing');

        $this->post(route('site.unsubscribe.leave', $reader->token), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertFalse($reader->fresh()->isActive());
    }

    public function test_the_email_carries_the_post_and_a_way_out(): void
    {
        $reader = Subscriber::create(['email' => 'a@example.com', 'confirmed_at' => now()]);
        $post = $this->publish('Kuih season', 30);

        $mail = (new NewPostEmail($post))->toMail($reader);
        $this->assertSame('Kuih season', $mail->subject);
        $this->assertSame(url($post->uri()), $mail->actionUrl);
        $this->assertStringContainsString(route('site.unsubscribe', $reader->token), implode(' ', $mail->outroLines));
    }

    public function test_the_subscribers_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'writer']))->get(route('admin.subscribers'))->assertForbidden();

        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor);
        Subscriber::create(['email' => 'a@example.com', 'confirmed_at' => now()]);

        Livewire::test(Subscribers::class)
            ->assertSee('a@example.com')
            ->set('inviteEmail', 'friend@example.com')->call('invite')
            ->call('toggle');

        $this->assertFalse(Newsletter::enabled(), 'paused');
        Notification::assertSentTo(Subscriber::firstWhere('email', 'friend@example.com'), ConfirmSubscription::class);

        $csv = $this->get(route('admin.subscribers.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('a@example.com', $csv);
        $this->assertStringNotContainsString('friend@example.com', $csv, 'only people who confirmed');
    }

    public function test_posts_without_a_picture_share_with_a_drawn_card(): void
    {
        if (! ShareImage::available()) {
            $this->markTestSkipped('This PHP has no GD with FreeType.');
        }
        $post = $this->publish('Kuih season', 30);
        $url = ShareImage::urlFor($post);

        $this->get($post->uri())->assertOk()->assertSee('<meta property="og:image" content="'.$url.'">', false);

        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/png');
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get(ltrim(str_replace('/storage/', '', $path), '/')));
        $this->assertSame([1200, 630], [$w, $h]);

        // A new title, a new card; the old address forwards.
        $post->set('title', 'Kuih season, again')->save();
        $this->get($path)->assertRedirect(ShareImage::urlFor(Booklet::entries()->find($post->id())));

        // Titles our fonts can't draw use the site's card.
        $this->assertNull(ShareImage::urlFor($this->publish('庭の春', 5)));
    }
}
