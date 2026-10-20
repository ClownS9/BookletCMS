<?php

namespace App\Stacy;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\WeeklyLetter as Letter;
use App\Support\VisitStats;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stacy's Monday letter: last week in a few plain paragraphs (visits, what
 * people read, what went out, what's coming, who's waiting for a reply).
 * Built from the book, no AI call. Sent to people with "Weekly summary" on.
 */
class WeeklyLetter
{
    /**
     * Send this week's letter to everyone who wants one (once per person per week).
     *
     * @return int how many were sent
     */
    public static function sendAll(bool $force = false): int
    {
        $sent = 0;
        $week = now()->format('o-W');

        User::query()->get()
            ->filter(fn (User $u) => (bool) (((array) $u->preferences)['weekly_summary'] ?? false) && $u->email)
            ->each(function (User $user) use (&$sent, $week, $force) {
                if (! $force && ! Cache::add('booklet.weekly-letter.'.$user->id.'.'.$week, true, now()->addDays(8))) {
                    return; // already had this week's
                }
                rescue(function () use ($user, &$sent) {
                    $user->notify(new Letter(self::for($user)));
                    $sent++;
                }, null, true);
            });

        return $sent;
    }

    /**
     * @return array{subject: string, hello: string, paragraphs: array<int, string>, signoff: string}
     */
    public static function for(User $user): array
    {
        $name = Str::before(trim($user->name), ' ') ?: 'there';
        $stats = rescue(fn () => VisitStats::summary(7), ['views' => 0, 'visitors' => 0, 'reads' => 0, 'previous' => 0, 'pages' => []], false);
        $entries = rescue(fn () => Booklet::entries()->all(), collect(), false);
        $from = now()->subDays(7);
        $paragraphs = [];

        // 1. Readers
        if ($stats['views'] > 0) {
            $change = $stats['previous'] > 0 ? (int) round(($stats['views'] - $stats['previous']) / $stats['previous'] * 100) : null;
            $trend = match (true) {
                $change === null => '',
                $change >= 10 => ', '.$change.'% more than the week before',
                $change <= -10 => ', '.abs($change).'% fewer than the week before',
                default => ', about the same as the week before',
            };
            $line = 'Last week the site had '.number_format($stats['views']).' '.Str::plural('page view', $stats['views'])
                .' from about '.number_format($stats['visitors']).' '.Str::plural('reader', $stats['visitors']).$trend.'.';
            if ($stats['reads'] > 0) {
                $line .= ' '.number_format($stats['reads']).' '.($stats['reads'] === 1 ? 'person read a post' : 'people read a post').' right to the end.';
            }
            $paragraphs[] = $line;

            $top = collect($stats['pages'])->filter(fn ($p) => $p['entry_id'])->take(3)
                ->map(fn ($p) => '“'.Str::limit(Booklet::entries()->find($p['entry_id'])?->title() ?: $p['path'], 60).'” ('.number_format($p['views']).')')
                ->values();
            if ($top->isNotEmpty()) {
                $paragraphs[] = 'Most read: '.$top->join(', ', ' and ').'.';
            }
        } else {
            $paragraphs[] = 'It was a quiet week for visitors. Sharing a post somewhere new can help.';
        }

        // 2. What went out
        $published = $entries->filter(fn (Entry $e) => $e->published() && $e->date()?->between($from, now()));
        if ($published->isNotEmpty()) {
            $titles = $published->take(3)->map(fn (Entry $e) => '“'.Str::limit($e->title() ?: 'Untitled', 60).'”')->values();
            $paragraphs[] = $published->count() === 1
                ? 'You published '.$titles[0].'.'
                : $published->count().' entries went out: '.$titles->join(', ', ' and ').($published->count() > 3 ? ' and more' : '').'.';
        }

        // 3. Coming up and in progress
        $soon = $entries->filter(fn (Entry $e) => $e->status() === 'scheduled' && $e->date()?->lte(now()->addDays(7)))->sortBy(fn (Entry $e) => $e->date());
        if ($next = $soon->first()) {
            $paragraphs[] = 'Coming up: “'.Str::limit($next->title() ?: 'Untitled', 60).'” on '.$next->date()->format('l').
                ($soon->count() > 1 ? ', and '.($soon->count() - 1).' more this week.' : '.');
        }
        $mine = $entries->filter(fn (Entry $e) => ! $e->published() && $e->status() === 'draft' && (string) $e->get('author') === (string) $user->id);
        if ($mine->isNotEmpty()) {
            $paragraphs[] = 'You have '.$mine->count().' '.Str::plural('draft', $mine->count()).' on the go'
                .($mine->count() === 1 ? ': “'.Str::limit($mine->first()->title() ?: 'Untitled', 60).'”.' : '.');
        }

        // 4. Readers waiting
        $waiting = rescue(fn () => Comment::where('status', 'waiting')->count(), 0, false);
        if ($waiting && in_array($user->level(), ['admin', 'editor'], true)) {
            $paragraphs[] = $waiting === 1 ? 'One reader’s comment is waiting for you.' : $waiting.' comments are waiting for you.';
        }

        return [
            'subject' => $stats['views'] > 0
                ? 'Your week: '.number_format($stats['views']).' '.Str::plural('view', $stats['views']).($published->isNotEmpty() ? ', '.$published->count().' published' : '')
                : 'Your week in the book',
            'hello' => 'Good morning, '.$name.'.',
            'paragraphs' => $paragraphs,
            'signoff' => Arr::random([
                'Have a good week. I’m here if you need a hand.',
                'Here’s to a good week of writing.',
                'Ask me anything when you open the book.',
            ]),
        ];
    }
}
