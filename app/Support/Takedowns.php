<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\Redirect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Posts and pages that come down by themselves: an offer that ends, an
 * event that's over. On the entry:
 *
 *   takedown_at   when it comes down (the site's time, "Y-m-d H:i:s")
 *   takedown_to   where readers are sent afterwards (optional: /offers, https://…)
 *   taken_down_at when it came down (set then, for the book to say so)
 *
 * When the time comes it goes back to being a draft (nothing is deleted),
 * with a temporary redirect if one was asked for. That happens on the first
 * visit after its time and every minute from Booklet's scheduler, so the
 * site is right even without the scheduler.
 */
class Takedowns
{
    /** When the next one is due (a timestamp; 0 for none), kept until something in the book changes. */
    public const NEXT_KEY = 'booklet.takedown.next';

    /** Where readers may be sent: this site, or the web. */
    public static function safeTo(string $to): bool
    {
        return (bool) preg_match('~^(/(?!/)[^\s]*|https?://[^\s/]+[^\s]*)$~i', $to);
    }

    /** When this one comes down, or null. */
    public static function at(Entry $entry): ?Carbon
    {
        $at = $entry->get('takedown_at');

        return is_string($at) && $at !== '' ? rescue(fn () => Carbon::parse($at, config('app.timezone')), null, false) : null;
    }

    /** When the next one comes down, or null. */
    public static function next(): ?Carbon
    {
        $at = (int) Cache::rememberForever(self::NEXT_KEY, function () {
            $next = rescue(fn () => Booklet::entries()->all()
                ->filter(fn (Entry $e) => $e->published())
                ->map(fn (Entry $e) => self::at($e))
                ->filter()
                ->min(), null, false);

            return $next instanceof Carbon ? $next->getTimestamp() : 0;
        });

        return $at > 0 ? Carbon::createFromTimestamp($at) : null;
    }

    /** Something in the book changed: work out the next one again. */
    public static function forget(): void
    {
        Cache::forget(self::NEXT_KEY);
    }

    /**
     * Take down whatever's due. Once a request is plenty; cheap when nothing
     * is (one cache read). Returns how many came down.
     */
    public static function settle(): int
    {
        if (! app()->runningInConsole()) {
            if (request()->attributes->get('booklet.takedowns.settled')) {
                return 0;
            }
            request()->attributes->set('booklet.takedowns.settled', true);
        }
        $next = self::next();
        if (! $next || $next->isFuture()) {
            return 0;
        }

        $due = rescue(fn () => Booklet::entries()->all()
            ->filter(fn (Entry $e) => $e->published() && self::at($e)?->lte(now())), collect(), false);
        foreach ($due as $entry) {
            self::takeDown($entry);
        }
        self::forget();

        return $due->count();
    }

    /** It comes down now: a draft again, with readers sent on if that was asked for. */
    public static function takeDown(Entry $entry): void
    {
        $site = app(\App\Booklet\Site::class);
        $address = Redirects::normalise(parse_url($site->url($entry), PHP_URL_PATH) ?: '/');
        $to = trim((string) $entry->get('takedown_to', ''));

        $entry->setAttribute('published', false)
            ->set('taken_down_at', now()->format('Y-m-d H:i:s'))
            ->remove('takedown_at')
            ->remove('takedown_to')
            ->save();

        if ($to !== '' && self::safeTo($to) && $address !== '/' && Redirects::normalise($to) !== $address) {
            // Temporary (302): it may come back one day. Its own page wins again if it does.
            rescue(fn () => Redirect::updateOrCreate(['from_path' => $address],
                ['to_path' => str_starts_with($to, '/') ? Redirects::normalise($to) : $to, 'status' => 302, 'entry_id' => (string) $entry->id(), 'automatic' => true]), null, false);
        }

        rescue(fn () => Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'taken down', null, 'Came down by itself, as planned'), null, false);
        ActivityLog::create([
            'user_id' => null,
            'actor' => 'booklet',
            'action' => 'took down',
            'kind' => 'publishing',
            'subject_type' => 'entry',
            'subject_key' => (string) $entry->id(),
            'subject_label' => $entry->title(),
            'properties' => array_filter(['as planned' => true, 'to' => $to ?: null]),
        ]);
        PageCache::flush();
    }
}
