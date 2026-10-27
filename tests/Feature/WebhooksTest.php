<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Webhooks as WebhooksPage;
use App\Models\Comment;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Webhooks;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Webhooks: "when this happens, call this address". Calls are written down,
 * sent once the response has gone (or by the scheduler), signed, and tried
 * again when the other end doesn't answer.
 */
class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        // Every name is a public address here, except one that points inside.
        Webhooks::$resolve = fn (string $host) => $host === 'inside.example' ? ['10.0.0.5'] : ['93.184.216.34'];
    }

    protected function tearDown(): void
    {
        Webhooks::$resolve = null;
        parent::tearDown();
    }

    protected function hook(array $events = Webhooks::SITE_CHANGES, string $format = 'json', array $more = []): Webhook
    {
        $hook = Webhook::create($more + ['name' => 'Rebuild', 'url' => 'https://api.netlify.example/build_hooks/abc', 'format' => $format,
            'events' => $events, 'secret' => 'whsec_test', 'active' => true]);
        Webhooks::forget();

        return $hook;
    }

    protected function livePost(): \App\Booklet\Data\Entry
    {
        Booklet::flush();

        return Booklet::entries()->all()->first(fn ($e) => $e->collectionHandle() === 'posts' && $e->status() === 'published') ?? $this->fail('no post');
    }

    public function test_an_admin_adds_one_from_what_its_for_and_tests_it(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $page = Livewire::actingAs($this->admin)->test(WebhooksPage::class)
            ->assertSee('Rebuild my static site')->assertSee('Tell our Slack channel')
            ->call('start', 'slack')->assertSet('chosen', 'new')->assertSet('format', 'slack')
            ->assertSet('events', ['entry.published', 'comment.received', 'message.received']);

        // Only addresses outside the server's own network.
        $page->set('url', 'https://inside.example/hook')->call('save')->assertHasErrors('url');
        $page->set('url', 'ftp://example.com/x')->call('save')->assertHasErrors('url');
        $page->set('events', [])->set('url', 'https://hooks.slack.com/services/T0/B0/xyz')->call('save')->assertHasErrors('events');
        $page->set('events', ['entry.published'])->call('save')->assertHasNoErrors();

        $hook = Webhook::sole();
        $this->assertSame('slack', $hook->format);
        $this->assertStringStartsWith('whsec_', $hook->secret);
        $this->assertNotSame($hook->secret, \DB::table('webhooks')->value('secret'), 'kept encrypted');
        $this->assertSame('https://hooks.slack.com/services/T0/B…', $hook->shownUrl());

        $page->call('test')->assertSet('tested.ok', true)->assertSee('The webhook works.');
        Http::assertSent(fn (Sent $r) => $r->url() === 'https://hooks.slack.com/services/T0/B0/xyz'
            && str_contains($r['text'], 'A test from') && $r->hasHeader('X-Booklet-Event', 'ping'));

        // Pasting a Discord address picks Discord.
        $page->set('url', 'https://discord.com/api/webhooks/1/abc')->assertSet('format', 'discord');

        // Editors don't see it.
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.settings.webhooks'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.settings.webhooks'))->assertOk()->assertSee('Webhooks');
        $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()->assertSee('1 webhook on');
    }

    public function test_publishing_changing_and_taking_down_call_it_signed(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $hook = $this->hook();

        // A new post going live.
        $entry = Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'slug' => 'kaya-toast', 'published' => true, 'date' => now()->subMinute()],
            ['title' => 'Kaya toast', 'content' => 'Butter, then kaya.'])->save();
        $this->assertSame(1, Webhooks::flush());
        Http::assertSent(function (Sent $r) {
            $body = $r->body();
            $expected = 'sha256='.hash_hmac('sha256', $r->header('X-Booklet-Timestamp')[0].'.'.$body, 'whsec_test');

            return $r->method() === 'POST' && $r->hasHeader('X-Booklet-Event', 'entry.published') && hash_equals($expected, $r->header('X-Booklet-Signature')[0])
                && $r['entry']['title'] === 'Kaya toast' && str_ends_with((string) $r['entry']['url'], '/kaya-toast') && $r['entry']['status'] === 'published';
        });

        // A few quick saves while live send once, a minute after the last.
        $entry->set('title', 'Kaya toast, again')->save();
        $entry->set('title', 'Kaya toast, the best')->save();
        $waiting = WebhookDelivery::where('event', 'entry.updated')->get();
        $this->assertCount(1, $waiting);
        $this->assertSame('Kaya toast, the best', $waiting[0]->payload['entry']['title']);
        $this->assertSame(0, Webhooks::flush(), 'not yet');
        $this->travel(61)->seconds();
        $this->assertSame(1, Webhooks::flush());

        // Back to a draft: the address it had, so a cache can forget it.
        $entry->setAttribute('published', false)->save();
        Webhooks::flush();
        $down = WebhookDelivery::where('event', 'entry.unpublished')->sole();
        $this->assertStringEndsWith('/kaya-toast', (string) $down->payload['entry']['url']);
        $this->assertSame('draft', $down->payload['entry']['status']);

        // Drafts coming and going aren't news; a live one going to the bin is.
        $entry->set('title', 'Draft fiddling')->save();
        $entry->delete();
        $this->assertSame(0, WebhookDelivery::whereIn('event', ['entry.updated', 'entry.deleted'])->where('status', 'pending')->count());
        $live = $this->livePost();
        \App\Support\Bin::entry($live, $this->admin);
        $this->assertSame(1, WebhookDelivery::where('event', 'entry.deleted')->count());

        // Switched off: nothing written down.
        $hook->forceFill(['active' => false])->save();
        $before = WebhookDelivery::count();
        Booklet::entries()->findBySlug('pages', 'about')->set('title', 'Quietly')->save();
        $this->assertSame($before, WebhookDelivery::count());
    }

    public function test_slack_and_discord_get_one_line_and_only_the_chosen_chapters(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->hook(['entry.published'], 'slack', ['collections' => ['pages']]);
        $discord = $this->hook(['entry.published', 'comment.received'], 'discord', ['url' => 'https://discord.com/api/webhooks/1/abc']);

        Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'slug' => 'roti-jala', 'published' => true],
            ['title' => 'Roti <jala> & curry'])->save();
        Webhooks::flush();
        Http::assertSentCount(1); // the Slack one only wants pages
        Http::assertSent(fn (Sent $r) => str_starts_with($r['content'], 'New on ') && str_contains($r['content'], '[“Roti <jala> & curry”](')
            && $r['allowed_mentions'] === ['parse' => []]);

        // A comment can't @everyone a Discord channel.
        $post = $this->livePost();
        $comment = Comment::create(['entry_id' => $post->id(), 'author_name' => 'Mak Cik', 'body' => '@everyone free cake', 'status' => 'pending']);
        app(\App\Inserts\InsertManager::class)->fire('comment.received', $comment);
        Webhooks::flush();
        Http::assertSent(fn (Sent $r) => isset($r['content']) && str_contains($r['content'], 'New comment (waiting for approval) from Mak Cik')
            && ! str_contains($r['content'], '@everyone'));
        $this->assertSame(0, $discord->fresh()->failures);

        $line = Webhooks::body('slack', ['event' => 'entry.published', 'site' => ['name' => 'A & B'], 'entry' => ['title' => '<b>', 'url' => 'https://x.example/p']]);
        $this->assertSame('New on A &amp; B: <https://x.example/p|“&lt;b&gt;”>', $line['text']);
    }

    public function test_an_address_that_doesnt_answer_is_tried_again_then_given_up(): void
    {
        $up = false;
        Http::fake(function () use (&$up) {
            return $up ? Http::response('ok', 200) : Http::response('Down for maintenance', 503);
        });
        $hook = $this->hook(['message.received']);

        \App\Support\Inbox::receive(['name' => 'Aminah', 'email' => 'aminah@example.com', 'message' => 'Do you cater?'], '/contact');
        $this->assertSame(0, Webhooks::flush());
        $d = WebhookDelivery::sole();
        $this->assertSame('pending', $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertSame(503, $d->response_code);
        $this->assertSame('Down for maintenance', $d->response_note);
        $this->assertSame('Do you cater?', $d->payload['message']['fields']['message']);
        $this->assertSame('Contact form', $d->payload['message']['form_name']);

        foreach ([1, 5, 30, 120] as $minutes) {
            $this->travel($minutes)->minutes();
            Webhooks::flush();
        }
        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame(5, $d->attempts);
        $this->assertSame(5, $hook->fresh()->failures);
        Livewire::actingAs($this->admin)->test(WebhooksPage::class)->assertSee('5 tries in a row didn’t go through')->assertSee('Down for maintenance');

        // Sent again by hand once it's back.
        $up = true;
        Livewire::actingAs($this->admin)->test(WebhooksPage::class)->call('resend', $d->id);
        $this->assertSame('sent', $d->fresh()->status);
        $this->assertSame(0, $hook->fresh()->failures);

        // An address that turns out to point inside isn't called at all, or tried again.
        $hook->forceFill(['url' => 'https://inside.example/x'])->save();
        Webhooks::forget();
        \App\Support\Inbox::receive(['name' => 'B', 'email' => 'b@example.com', 'message' => 'Hi'], '/contact');
        Webhooks::flush();
        $last = WebhookDelivery::latest('id')->first();
        $this->assertSame('failed', $last->status);
        $this->assertStringContainsString('private network', (string) $last->response_note);
        Http::assertSentCount(6); // five tries and the one by hand; none inside
    }

    public function test_a_scheduled_post_counts_as_published_when_its_time_comes(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->hook(['entry.published']);
        $at = now()->addHour()->startOfMinute();
        Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'slug' => 'nasi-lemak', 'published' => true, 'date' => $at],
            ['title' => 'Nasi lemak'])->save();
        $this->assertSame(0, WebhookDelivery::count(), 'scheduled is not live');
        $this->assertSame(0, Webhooks::goneLive());

        $this->travelTo($at->copy()->addMinutes(2));
        request()->attributes->remove('booklet.webhooks.live-checked');
        $this->assertSame(1, Webhooks::settle());
        Http::assertSent(fn (Sent $r) => $r['event'] === 'entry.published' && $r['entry']['title'] === 'Nasi lemak');

        // Only once.
        request()->attributes->remove('booklet.webhooks.live-checked');
        $this->assertSame(0, Webhooks::goneLive());
        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_after_a_request_what_is_due_goes_out(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->hook(['entry.published']);
        Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'slug' => 'teh-tarik', 'published' => true], ['title' => 'Teh tarik'])->save();
        $this->assertTrue(Webhooks::due());
        $this->get('/')->assertOk(); // the response goes, then it's sent
        Http::assertSent(fn (Sent $r) => $r['entry']['title'] === 'Teh tarik');
        $this->assertFalse(Webhooks::due());
    }
}
