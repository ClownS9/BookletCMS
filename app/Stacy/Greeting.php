<?php

namespace App\Stacy;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Stacy says hello: when someone signs in, opens the book for the first time,
 * or comes back after a while away. Built from the book itself (no AI call),
 * so it's instant and free: their name, the time of day, and the one or two
 * things most worth knowing, with a button for each.
 *
 * Switched on or off for everyone in Settings › How Stacy behaves, and for
 * yourself in Your account (or "Don't greet me" on the greeting).
 */
class Greeting
{
    /** Away at least this long and she says "welcome back". */
    public const AWAY_HOURS = 4;

    public static function wanted(?User $user): bool
    {
        return $user
            && StacyConfig::get('greet')
            && StacyConfig::canUse($user)
            && (bool) (((array) $user->preferences)['stacy_greet'] ?? true);
    }

    /**
     * @param  string  $kind  first · login · back
     * @return array{kind: string, title: string, lines: array<int, string>, actions: array<int, array{label: string, href?: string, ask?: string}>, say: string, aloud: bool, sound: bool}
     */
    public static function for(User $user, string $kind, ?\Carbon\CarbonInterface $since = null): array
    {
        $name = Str::before(trim($user->name), ' ') ?: 'there';
        $facts = $kind === 'first' ? [] : self::facts($user, $kind === 'back' ? $since : null);

        [$title, $lines, $actions] = match ($kind) {
            'first' => [
                'Welcome to your book, '.$name.'!',
                [
                    'I’m Stacy. I live in the corner of every page.',
                    'Ask me to find things, write a first draft, tidy tags or explain anything here. Type, talk, or press Alt+S.',
                ],
                [
                    ['label' => 'Write my first post', 'href' => route('admin.entries.create', 'posts')],
                    ['label' => 'Show me around', 'ask' => 'Give me a quick tour of what I can do in this book.'],
                ],
            ],
            'back' => [
                Arr::random([
                    'Welcome back, '.$name.'.',
                    'Good to see you again, '.$name.'.',
                    'There you are, '.$name.'.',
                ]),
                array_values(array_filter([
                    $since ? 'You were away '.self::away($since).'.' : null,
                    ...array_column(array_slice($facts, 0, 2), 'line'),
                ])),
                self::actions($facts),
            ],
            default => [
                self::hello().', '.$name.'.',
                array_column(array_slice($facts, 0, 2), 'line'),
                self::actions($facts),
            ],
        };

        if ($kind !== 'first' && ! $facts) {
            $lines[] = Arr::random([
                'All quiet in the book. A good time to write.',
                'Nothing waiting for you. The pages are yours.',
                'Everything’s in order. What shall we work on?',
            ]);
            $actions = [['label' => 'Start a draft', 'href' => route('admin.entries.create', 'posts')], ['label' => 'Brief me', 'ask' => '/brief']];
        }

        return [
            'kind' => $kind,
            'title' => $title,
            'lines' => array_values($lines),
            'actions' => array_slice($actions, 0, 3),
            // What she says out loud: the whole greeting (it's only a few short sentences).
            'say' => trim($title.' '.implode(' ', array_slice(array_values($lines), 0, 2))),
            'aloud' => (bool) StacyConfig::get('greet_aloud'),
            'sound' => (bool) StacyConfig::get('greet_sound'),
        ];
    }

    /** "Good morning" and friends, by the site's own clock. */
    public static function hello(?Carbon $at = null): string
    {
        $hour = ($at ?? now())->hour;

        return match (true) {
            $hour < 5 => 'Up late',
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            $hour < 22 => 'Good evening',
            default => 'Working late',
        };
    }

    protected static function away(\Carbon\CarbonInterface $since): string
    {
        $hours = (int) $since->diffInHours(now(), true);

        return match (true) {
            $hours < 24 => $hours.' '.Str::plural('hour', $hours),
            $hours < 48 => 'since yesterday',
            $hours < 24 * 14 => intdiv($hours, 24).' days',
            default => 'a little while',
        };
    }

    /**
     * The things worth knowing, most useful first: [line, action].
     *
     * @return array<int, array{line: string, action: array|null}>
     */
    protected static function facts(User $user, ?\Carbon\CarbonInterface $since): array
    {
        $facts = [];
        $entries = rescue(fn () => Booklet::entries()->all(), collect(), false);
        $moderates = in_array($user->level(), ['admin', 'editor'], true);

        // Readers waiting for a reply.
        $waiting = rescue(fn () => Comment::where('status', 'waiting')->count(), 0, false);
        if ($waiting && $moderates) {
            $facts[] = [
                'line' => $waiting === 1 ? 'A reader’s comment is waiting for you.' : $waiting.' comments are waiting for you.',
                'action' => ['label' => 'See comments', 'href' => route('admin.comments')],
            ];
        }

        // While they were away: what went live.
        if ($since) {
            $published = $entries->filter(fn (Entry $e) => $e->published() && $e->date()?->between($since, now()));
            if ($published->isNotEmpty()) {
                $first = $published->sortByDesc(fn (Entry $e) => $e->date())->first();
                $facts[] = [
                    'line' => $published->count() === 1
                        ? '“'.Str::limit($first->title() ?: 'Untitled', 48).'” went live while you were out.'
                        : $published->count().' entries went live while you were out.',
                    'action' => ['label' => 'Open it', 'href' => route('admin.entries', [$first->collectionHandle(), 'entry' => $first->id()])],
                ];
            }
        }

        // Going out today.
        $today = $entries->filter(fn (Entry $e) => $e->status() === 'scheduled' && $e->date()?->isToday())->sortBy(fn (Entry $e) => $e->date());
        if ($next = $today->first()) {
            $facts[] = [
                'line' => '“'.Str::limit($next->title() ?: 'Untitled', 48).'” goes out at '.$next->date()->format('g:ia').'.'
                    .($today->count() > 1 ? ' '.($today->count() - 1).' more after it today.' : ''),
                'action' => ['label' => 'Calendar', 'href' => route('admin.calendar')],
            ];
        }

        // Their own draft, still warm.
        $mine = $entries
            ->filter(fn (Entry $e) => ! $e->published() && $e->status() === 'draft' && (string) $e->get('author') === (string) $user->id)
            ->sortByDesc(fn (Entry $e) => (string) $e->lastModified())
            ->first();
        if ($mine) {
            $facts[] = [
                'line' => sprintf(
                    Arr::random(['Your draft “%s” is where you left it.', 'Pick up “%s” where you left off?']),
                    Str::limit($mine->title() ?: 'Untitled', 48),
                ),
                'action' => ['label' => 'Keep writing', 'href' => route('admin.entries.edit', [$mine->collectionHandle(), $mine->id()])],
            ];
        }

        // Sticky notes left on their entries.
        $notes = rescue(fn () => \App\Models\EntryNote::query()
            ->whereNull('done_at')
            ->where('user_id', '!=', $user->id)
            ->whereIn('entry_id', $entries->filter(fn (Entry $e) => (string) $e->get('author') === (string) $user->id)->map->id()->values())
            ->count(), 0, false);
        if ($notes) {
            $facts[] = [
                'line' => $notes === 1 ? 'Someone left a sticky note on one of your entries.' : $notes.' sticky notes are waiting on your entries.',
                'action' => ['label' => 'My entries', 'href' => route('admin.entries', 'posts')],
            ];
        }

        return $facts;
    }

    /** One button per fact, then "Brief me" if there's room. */
    protected static function actions(array $facts): array
    {
        $actions = array_values(array_filter(array_column(array_slice($facts, 0, 2), 'action')));
        $actions[] = ['label' => 'Brief me', 'ask' => '/brief'];

        return $actions;
    }
}
