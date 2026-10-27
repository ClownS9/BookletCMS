<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Submission;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Models\Comment;
use App\Models\Subscriber;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhooks: "when this happens, call this address". A static host rebuilds,
 * a Slack or Discord channel hears about it, Zapier or Make takes it from there.
 *
 * Something happens → a delivery is written down (due now, or in a minute for
 * changes to a live page, so a few quick saves send once) → it's sent after
 * a response has gone back to the browser (any request), or by the scheduler
 * every minute.
 * An address that doesn't answer is tried again after 1, 5, 30 and 120 minutes.
 *
 * Every call is signed: X-Booklet-Signature is "sha256=" and the HMAC-SHA256 of
 * "{X-Booklet-Timestamp}.{body}" with the webhook's secret.
 */
class Webhooks
{
    /** What can set one off, in the order they're shown. [label, hint] */
    public const EVENTS = [
        'entry.published' => ['A post or page goes live', 'Scheduled ones too, when their time comes.'],
        'entry.updated' => ['A live post or page is changed', 'Sent a minute after the last save, so a few quick saves send once.'],
        'entry.unpublished' => ['A post or page comes down', 'Turned back into a draft, or taken down on its date.'],
        'entry.deleted' => ['A live post or page is deleted', 'Moved to the bin.'],
        'comment.received' => ['A comment arrives', 'Waiting ones too; the status says which.'],
        'message.received' => ['A message or form answer arrives', 'Not ones that look like spam.'],
        'subscriber.confirmed' => ['Someone subscribes', 'Once they’ve confirmed their email.'],
    ];

    /** The four that change what readers see: what a static host needs to rebuild. */
    public const SITE_CHANGES = ['entry.published', 'entry.updated', 'entry.unpublished', 'entry.deleted'];

    public const FORMATS = ['json' => 'JSON (Zapier, Make, a static host, your own code)', 'slack' => 'A Slack message', 'discord' => 'A Discord message'];

    /** Waits before trying again, in minutes (then it's given up). */
    public const RETRY = [1, 5, 30, 120];

    public const MAX_HOOKS = 20;

    /** Deliveries kept for each webhook. */
    public const KEEP = 50;

    /** Changes to a live page wait this long for more changes. */
    public const SETTLE_SECONDS = 60;

    protected const DUE_KEY = 'booklet.webhooks.due';

    protected const NEXT_LIVE_KEY = 'booklet.webhooks.next-live';

    /** How an address becomes IP addresses, for the private-network check (replaceable in tests). */
    public static ?\Closure $resolve = null;

    // —— what happened ——

    /** An entry was saved: work out whether that's news, from whether it was live before. */
    public static function entrySaved(Entry $entry, bool $wasLive, ?string $oldUrl = null): void
    {
        $live = $entry->status() === 'published';
        $event = $live ? ($wasLive ? 'entry.updated' : 'entry.published') : ($wasLive ? 'entry.unpublished' : null);
        if ($event && self::wants($event)) {
            self::trigger($event, self::entryPayload($entry, $live ? null : $oldUrl), $entry->id(), $entry->collectionHandle());
        }
    }

    public static function entryDeleted(Entry $entry): void
    {
        if ($entry->status() === 'published' && self::wants('entry.deleted')) {
            self::trigger('entry.deleted', self::entryPayload($entry, app(Site::class)->absolute(app(Site::class)->url($entry))), $entry->id(), $entry->collectionHandle());
        }
    }

    /** The events inserts hear about too (see InsertManager::fire). */
    public static function heard(string $event, mixed $subject): void
    {
        if (! self::wants($event)) {
            return;
        }
        if ($event === 'comment.received' && $subject instanceof Comment) {
            $entry = $subject->entry_id ? Booklet::entries()->find((string) $subject->entry_id) : null;
            self::trigger($event, ['comment' => [
                'id' => $subject->id,
                'status' => $subject->status,
                'author_name' => $subject->author_name,
                'author_email' => $subject->author_email,
                'body' => $subject->body,
                'reply_to' => $subject->parent_id,
                'created_at' => $subject->created_at?->toIso8601String(),
            ], 'entry' => $entry ? self::entryPayload($entry)['entry'] : null], (string) $subject->id, $entry?->collectionHandle());
        } elseif ($event === 'message.received' && $subject instanceof Submission) {
            $fields = collect($subject->data())->reject(fn ($v, $k) => str_starts_with((string) $k, '_'))->all();
            self::trigger($event, ['message' => [
                'id' => $subject->id(),
                'form' => $subject->form(),
                'form_name' => Inbox::formName($subject),
                'fields' => $fields,
                'page' => $subject->get('_page'),
                'received_at' => $subject->get('_at'),
            ]], $subject->id());
        } elseif ($event === 'subscriber.confirmed' && $subject instanceof Subscriber) {
            self::trigger($event, ['subscriber' => [
                'email' => $subject->email,
                'name' => $subject->name,
                'confirmed_at' => $subject->confirmed_at?->toIso8601String(),
            ]], (string) $subject->id);
        }
    }

    /**
     * Scheduled posts whose time has come count as published. Cheap to call on
     * every request: it only looks when the earliest scheduled time has passed.
     */
    public static function goneLive(): int
    {
        if (! self::wants('entry.published')) {
            return 0;
        }
        if (! app()->runningInConsole()) {
            if (request()->attributes->get('booklet.webhooks.live-checked')) {
                return 0;
            }
            request()->attributes->set('booklet.webhooks.live-checked', true);
        }

        $from = (int) Cache::rememberForever(self::NEXT_LIVE_KEY, function () {
            $next = rescue(fn () => Booklet::entries()->all()
                ->filter(fn (Entry $e) => $e->status() === 'scheduled')
                ->map(fn (Entry $e) => $e->date())->filter()->min(), null, false);

            return $next instanceof Carbon ? $next->getTimestamp() : 0;
        });
        if ($from === 0 || $from > now()->getTimestamp()) {
            return 0;
        }

        // Live now, dated since the earliest one we were waiting for, and saved before
        // its time (so it was scheduled, not published straight away with an old date).
        $live = rescue(fn () => Booklet::entries()->all()->filter(fn (Entry $e) => $e->status() === 'published'
            && $e->date() && $e->date()->getTimestamp() >= $from && $e->date()->lte(now())
            && $e->lastModified() && Carbon::parse($e->lastModified())->lt($e->date())), collect(), false);
        Cache::forget(self::NEXT_LIVE_KEY);
        foreach ($live as $entry) {
            self::trigger('entry.published', self::entryPayload($entry), $entry->id(), $entry->collectionHandle());
        }

        return $live->count();
    }

    /** Entries changed: before the next scheduled time is worked out again, catch any that just went live. */
    public static function entriesChanged(): void
    {
        if (Cache::has(self::NEXT_LIVE_KEY)) {
            rescue(fn () => self::goneLive(), null, false);
            Cache::forget(self::NEXT_LIVE_KEY);
        }
    }

    // —— writing deliveries down ——

    /**
     * @param  array<string, mixed>  $data
     */
    public static function trigger(string $event, array $data, ?string $subject = null, ?string $collection = null): int
    {
        $hooks = self::active()->filter(fn (Webhook $h) => in_array($event, (array) $h->events, true)
            && (! $collection || ! $h->collections || ! str_starts_with($event, 'entry.') || in_array($collection, $h->collections, true)));
        if ($hooks->isEmpty()) {
            return 0;
        }

        $settle = $event === 'entry.updated';
        $due = $settle ? now()->addSeconds(self::SETTLE_SECONDS) : now();
        foreach ($hooks as $hook) {
            $payload = self::envelope($event, $data);
            $waiting = $settle && $subject ? $hook->deliveries()->where(['event' => $event, 'subject' => $subject, 'status' => 'pending'])->where('attempts', 0)->first() : null;
            if ($waiting) {
                $waiting->forceFill(['payload' => $payload, 'due_at' => $due])->save(); // the newest version, a minute after the last save
            } else {
                $hook->deliveries()->create(['event' => $event, 'subject' => $subject, 'payload' => $payload, 'status' => 'pending', 'due_at' => $due]);
            }
        }
        self::markDue($due); // sent once the response has gone (AppServiceProvider), or by the scheduler

        return $hooks->count();
    }

    /** True when some active webhook wants to hear about this. */
    public static function wants(string $event): bool
    {
        return self::active()->contains(fn (Webhook $h) => in_array($event, (array) $h->events, true));
    }

    /** @return Collection<int, Webhook> */
    public static function active(): Collection
    {
        // Kept for this request (in the container, so each request, job and test starts afresh).
        if (! app()->bound('booklet.webhooks.active')) {
            app()->instance('booklet.webhooks.active', rescue(fn () => Webhook::query()->where('active', true)->get(), collect(), false));
        }

        return app('booklet.webhooks.active');
    }

    /** Webhooks were changed: look again. */
    public static function forget(): void
    {
        app()->forgetInstance('booklet.webhooks.active');
    }

    protected static function markDue(Carbon $due): void
    {
        $now = (int) Cache::get(self::DUE_KEY, 0);
        if ($now === 0 || $due->getTimestamp() < $now) {
            Cache::forever(self::DUE_KEY, $due->getTimestamp());
        }
    }

    /** Anything waiting that's due? (Checked on every request; costs a cache read.) */
    public static function due(): bool
    {
        $at = (int) Cache::get(self::DUE_KEY, 0);

        return $at > 0 && $at <= now()->getTimestamp();
    }

    /**
     * After each request (the response has already gone) and from the scheduler
     * every minute: scheduled posts that went live, then whatever is due.
     */
    public static function settle(): int
    {
        rescue(fn () => self::goneLive(), null, false);

        return self::due() ? self::flush() : 0;
    }

    // —— sending ——

    /** Send what's due (a few at a time). Returns how many went. */
    public static function flush(int $limit = 10): int
    {
        $lock = Cache::lock('booklet.webhooks.sending', 120);
        if (! $lock->get()) {
            return 0;
        }
        $sent = 0;
        try {
            $started = microtime(true);
            $due = WebhookDelivery::query()->with('webhook')->where('status', 'pending')->where('due_at', '<=', now())
                ->orderBy('due_at')->limit($limit)->get();
            foreach ($due as $delivery) {
                if (microtime(true) - $started > 40) {
                    break; // the rest next time
                }
                if (self::send($delivery)) {
                    $sent++;
                }
            }
            $next = WebhookDelivery::query()->where('status', 'pending')->min('due_at');
            $next ? Cache::forever(self::DUE_KEY, Carbon::parse($next)->getTimestamp()) : Cache::forget(self::DUE_KEY);
        } finally {
            $lock->release();
        }

        return $sent;
    }

    /** Send one delivery now. True when the other end said yes (2xx). */
    public static function send(WebhookDelivery $delivery): bool
    {
        $hook = $delivery->webhook;
        if (! $hook) {
            $delivery->delete();

            return false;
        }
        if (! $hook->active && $delivery->event !== 'ping') {
            $delivery->forceFill(['status' => 'failed', 'response_note' => 'The webhook was switched off.'])->save();

            return false;
        }

        [$code, $note] = self::post($hook, $delivery);
        $ok = $code >= 200 && $code < 300;
        $attempts = $delivery->attempts + 1;
        $retry = ! $ok && $code !== -1 && isset(self::RETRY[$attempts - 1]) && $delivery->event !== 'ping';

        $delivery->forceFill([
            'attempts' => $attempts,
            'response_code' => $code > 0 ? $code : null,
            'response_note' => Str::limit($note, 290),
            'status' => $ok ? 'sent' : ($retry ? 'pending' : 'failed'),
            'due_at' => $retry ? now()->addMinutes(self::RETRY[$attempts - 1]) : $delivery->due_at,
            'sent_at' => $ok ? now() : $delivery->sent_at,
        ])->save();

        $hook->forceFill([
            'last_status' => $code > 0 ? $code : 0,
            'last_sent_at' => now(),
            'failures' => $ok ? 0 : $hook->failures + 1,
        ])->save();

        // Keep the newest ones only.
        $keep = $hook->deliveries()->orderByDesc('id')->skip(self::KEEP)->take(1)->value('id');
        if ($keep) {
            $hook->deliveries()->where('id', '<=', $keep)->where('status', '!=', 'pending')->delete();
        }

        return $ok;
    }

    /** A "ping" sent straight away from Settings, to see that the address answers. */
    public static function test(Webhook $hook): WebhookDelivery
    {
        $delivery = $hook->deliveries()->create([
            'event' => 'ping', 'subject' => null, 'status' => 'pending', 'due_at' => now(),
            'payload' => self::envelope('ping', ['message' => 'A test from Booklet. If you can read this, the webhook works.']),
        ]);
        self::send($delivery);

        return $delivery->fresh() ?? $delivery;
    }

    /**
     * @return array{0: int, 1: string} the status code (0: no answer, -1: not allowed) and a few words
     */
    protected static function post(Webhook $hook, WebhookDelivery $delivery): array
    {
        if (! self::allowed($hook->url, $why)) {
            return [-1, (string) $why]; // in words the page translates ("it points inside a private network")
        }

        $body = json_encode(self::body($hook->format, $delivery->payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Booklet-Webhooks/1',
                'X-Booklet-Event' => $delivery->event,
                'X-Booklet-Delivery' => (string) $delivery->id,
                'X-Booklet-Timestamp' => $timestamp,
                'X-Booklet-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $hook->secret),
            ])->withBody($body, 'application/json')
                ->timeout(10)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])
                ->post($hook->url);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $response = $e->response; // an answer, just not a happy one
        } catch (Throwable $e) {
            return [0, Str::limit(preg_replace('/^cURL error \d+: /', '', $e->getMessage()) ?? '', 160)];
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($response->body())) ?? '');

        return [$response->status(), $text !== '' ? $text : ($response->successful() ? 'OK' : $response->reason())];
    }

    /** Only http(s) to public addresses: nobody can use a webhook to poke at the server's own network. */
    public static function allowed(string $url, ?string &$why = null): bool
    {
        $before = SafeFetch::$resolve;
        if (self::$resolve) {
            SafeFetch::$resolve = self::$resolve;
        }
        try {
            return SafeFetch::allowed($url, $why);
        } finally {
            SafeFetch::$resolve = $before;
        }
    }

    // —— what's sent ——

    /** @param  array<string, mixed>  $data */
    protected static function envelope(string $event, array $data): array
    {
        $site = app(Site::class);

        return [
            'event' => $event,
            'happened_at' => now()->toIso8601String(),
            'site' => ['name' => $site->name(), 'url' => $site->absolute(url('/'))],
        ] + $data;
    }

    /** @return array{entry: array<string, mixed>} */
    public static function entryPayload(Entry $entry, ?string $url = null): array
    {
        $site = app(Site::class);
        $author = rescue(fn () => $site->author($entry), null, false);
        $live = $entry->status() === 'published';

        return ['entry' => [
            'id' => $entry->id(),
            'title' => $entry->title(),
            'collection' => $entry->collectionHandle(),
            'status' => $entry->status(),
            'url' => $live ? $site->absolute($site->url($entry)) : $url,
            'date' => $entry->date()?->toIso8601String(),
            'updated_at' => $entry->lastModified() ? Carbon::parse($entry->lastModified())->toIso8601String() : null,
            'author' => $author?->name,
            'excerpt' => rescue(fn () => $site->excerpt($entry), '', false) ?: null,
        ]];
    }

    /**
     * What goes over the wire: everything as JSON, or one line for a chat channel.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function body(string $format, array $payload): array
    {
        if ($format === 'slack') {
            return ['text' => self::line($payload, fn (string $s) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s), fn ($text, $url) => "<{$url}|{$text}>")];
        }
        if ($format === 'discord') {
            // No @everyone or @someone from a comment's text.
            return ['content' => Str::limit(self::line($payload, fn (string $s) => str_replace(['@', '`'], ['@​', 'ˋ'], $s), fn ($text, $url) => "[{$text}]({$url})"), 1900), 'allowed_mentions' => ['parse' => []]];
        }

        return $payload;
    }

    /**
     * One line saying what happened, for Slack or Discord.
     *
     * @param  array<string, mixed>  $p
     * @param  \Closure(string): string  $esc
     * @param  \Closure(string, string): string  $link
     */
    public static function line(array $p, \Closure $esc, \Closure $link): string
    {
        $site = $esc((string) ($p['site']['name'] ?? 'Booklet'));
        $entry = (array) ($p['entry'] ?? []);
        $title = $esc('“'.Str::limit((string) ($entry['title'] ?? 'Untitled'), 120).'”');
        $named = ! empty($entry['url']) ? $link($title, (string) $entry['url']) : $title;

        return match ($p['event'] ?? '') {
            'entry.published' => "New on {$site}: {$named}".(! empty($entry['author']) ? ' by '.$esc((string) $entry['author']) : ''),
            'entry.updated' => "Updated on {$site}: {$named}",
            'entry.unpublished' => "Taken down from {$site}: {$title}",
            'entry.deleted' => "Deleted from {$site}: {$title}",
            'comment.received' => 'New comment'.(($p['comment']['status'] ?? '') === 'pending' ? ' (waiting for approval)' : '')
                .' from '.$esc((string) ($p['comment']['author_name'] ?? 'someone')).($entry ? ' on '.$named : '')
                .': '.$esc(Str::limit(trim((string) ($p['comment']['body'] ?? '')), 200)),
            'message.received' => 'New '.$esc(Str::lower((string) ($p['message']['form_name'] ?? 'message')))." answer on {$site}",
            'subscriber.confirmed' => "Someone new subscribed to {$site}.",
            'ping' => "A test from {$site}. The webhook works.",
            default => $esc((string) ($p['event'] ?? 'Something happened'))." on {$site}",
        };
    }

    /** A fresh secret for signing. */
    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /** Slack and Discord addresses say what they are. */
    public static function guessFormat(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            $host === 'hooks.slack.com' => 'slack',
            in_array($host, ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'], true) && str_contains($url, '/api/webhooks/') => 'discord',
            default => 'json',
        };
    }
}
