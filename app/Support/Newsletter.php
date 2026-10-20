<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Models\PostEmail;
use App\Models\Subscriber;
use App\Notifications\ConfirmSubscription;
use App\Notifications\NewPostEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * New posts by email. Readers sign up on the site and confirm from their
 * inbox (nobody is added without saying yes). A few minutes after a post
 * goes live (scheduled ones too) it's sent to everyone subscribed, once;
 * every email carries a one-click way out.
 */
class Newsletter
{
    /** Minutes to wait after a post goes live (time to catch a typo, or take it back). */
    public const GRACE_MINUTES = 10;

    /** Posts older than this when found aren't sent (a missed day doesn't mean a flood). */
    public const LOOKBACK_DAYS = 3;

    public static function enabled(): bool
    {
        // Read fresh (not the page's copy of the settings), so a switch takes effect at once.
        return (bool) (Booklet::globals('site')?->get('newsletter', true) ?? true);
    }

    public static function setEnabled(bool $on): void
    {
        $site = Booklet::globals('site');
        if (! $site) {
            return;
        }
        $site->merge(['newsletter' => $on] + ($on ? ['newsletter_since' => now()->toIso8601String()] : []));
        Booklet::globalVariables()->save($site);
    }

    /** Posts published before this moment are never sent (so switching on doesn't email the archive). */
    public static function since(): Carbon
    {
        $since = Booklet::globals('site')?->get('newsletter_since');
        if (! $since) {
            $site = Booklet::globals('site');
            if ($site) {
                $site->merge(['newsletter_since' => now()->toIso8601String()]);
                Booklet::globalVariables()->save($site);
            }

            return Carbon::now();
        }

        return Carbon::parse($since);
    }

    // Readers ---------------------------------------------------------------------

    /**
     * Someone asked to subscribe. Returns 'sent' (check your email), 'already'
     * (subscribed already) or 'wait' (a confirmation went out moments ago).
     */
    public static function subscribe(string $email, ?string $name = null, string $source = 'site'): string
    {
        $email = mb_strtolower(trim($email));
        $subscriber = Subscriber::where('email', $email)->first();

        if ($subscriber?->isActive()) {
            return 'already';
        }
        if ($subscriber && ! $subscriber->unsubscribed_at && $subscriber->updated_at?->gt(now()->subMinutes(2))) {
            return 'wait';
        }

        $subscriber ??= new Subscriber(['email' => $email, 'source' => $source]);
        $subscriber->fill([
            'name' => $name ? Str::limit(trim($name), 120, '') : $subscriber->name,
            'confirmed_at' => null,
            'unsubscribed_at' => null,
            'token' => Str::random(48), // a fresh link each time
        ])->save();
        $subscriber->touch();

        rescue(fn () => $subscriber->notify(new ConfirmSubscription), null, true);

        return 'sent';
    }

    public static function confirm(string $token): ?Subscriber
    {
        $subscriber = self::byToken($token);
        if (! $subscriber || $subscriber->unsubscribed_at) {
            return $subscriber;
        }
        if (! $subscriber->confirmed_at) {
            $subscriber->forceFill(['confirmed_at' => now()])->save();
            app(\App\Inserts\InsertManager::class)->fire('subscriber.confirmed', $subscriber);
        }

        return $subscriber;
    }

    public static function unsubscribe(string $token): ?Subscriber
    {
        $subscriber = self::byToken($token);
        if ($subscriber && ! $subscriber->unsubscribed_at) {
            $subscriber->forceFill(['unsubscribed_at' => now()])->save();
        }

        return $subscriber;
    }

    public static function byToken(string $token): ?Subscriber
    {
        return preg_match('/^[A-Za-z0-9]{48}$/', $token) ? Subscriber::where('token', $token)->first() : null;
    }

    // Sending ---------------------------------------------------------------------

    /**
     * Posts that are live, new enough, and not emailed yet.
     *
     * @return Collection<int, Entry>
     */
    public static function due(): Collection
    {
        $since = self::since();
        $from = $since->max(now()->subDays(self::LOOKBACK_DAYS));
        $until = now()->subMinutes(self::GRACE_MINUTES);
        $done = PostEmail::pluck('entry_id')->flip();

        return app(Site::class)->posts() // originals only, published only
            ->filter(fn (Entry $e) => $e->date() && $e->date()->gte($from) && $e->date()->lte($until))
            ->reject(fn (Entry $e) => $e->get('no_email') || $done->has((string) $e->id()))
            ->sortBy(fn (Entry $e) => $e->date()->timestamp)
            ->values();
    }

    /** Send whatever is due (every few minutes, from the scheduler). Returns emails sent. */
    public static function sendDue(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $sent = 0;
        foreach (self::due() as $entry) {
            $sent += self::send($entry);
        }

        return $sent;
    }

    /**
     * Send one post to everyone subscribed, once. Returns how many it went to.
     */
    public static function send(Entry $entry): int
    {
        // Claim it first, so two runs at once can't both send it.
        try {
            $record = PostEmail::create(['entry_id' => (string) $entry->id(), 'title' => $entry->title(), 'status' => 'sending']);
        } catch (Throwable) {
            return 0; // already sent (or being sent)
        }

        $count = 0;
        Subscriber::active()->orderBy('id')->chunkById(100, function ($subscribers) use ($entry, &$count) {
            foreach ($subscribers as $subscriber) {
                try {
                    $subscriber->notify(new NewPostEmail($entry));
                    $subscriber->forceFill(['emails_sent' => $subscriber->emails_sent + 1, 'last_sent_at' => now()])->save();
                    $count++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        $record->update(['recipients' => $count, 'status' => $count > 0 ? 'sent' : 'skipped', 'sent_at' => now()]);

        return $count;
    }

    /** Numbers for the Subscribers page and Contents. */
    public static function counts(): array
    {
        return rescue(fn () => [
            'subscribed' => Subscriber::active()->count(),
            'waiting' => Subscriber::whereNull('confirmed_at')->whereNull('unsubscribed_at')->count(),
            'unsubscribed' => Subscriber::whereNotNull('unsubscribed_at')->count(),
        ], ['subscribed' => 0, 'waiting' => 0, 'unsubscribed' => 0], false);
    }

    /** Forget people who never confirmed, after a month. */
    public static function tidy(): int
    {
        return Subscriber::whereNull('confirmed_at')->whereNull('unsubscribed_at')->where('updated_at', '<', now()->subDays(30))->delete();
    }
}
