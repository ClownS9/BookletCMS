<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Milestone stickers: little rewards for things worth marking. Your first
 * post, ten posts, ten thousand words, four weeks running; and, for the
 * whole book, the first note from a reader, a thousand visits, birthdays.
 *
 * Nothing extra is stored about earning them: each sticker is worked out
 * from what's already in the book, with the day it was earned (the day of
 * the tenth post, say). That keeps them honest after a restore or an import.
 * The only thing kept is which ones you've already seen celebrated.
 */
class Milestones
{
    /** Only celebrate stickers earned this recently (no fanfare for old news after a restore). */
    public const FRESH_DAYS = 30;

    public const SEEN = 'stickers_seen';

    /**
     * key => [title, line when earned, line while waiting, big text, tone, scope, goal]
     */
    public const STICKERS = [
        'post-1' => ['First post', 'Your first page out in the world.', 'Publish your first post.', '1st', 'coral', 'yours', 1],
        'post-10' => ['Ten posts', 'Ten pages published.', 'Publish 10 posts.', '10', 'sun', 'yours', 10],
        'post-25' => ['Twenty-five posts', 'A proper chapter’s worth.', 'Publish 25 posts.', '25', 'mint', 'yours', 25],
        'post-50' => ['Fifty posts', 'Fifty pages. That’s a habit.', 'Publish 50 posts.', '50', 'sky', 'yours', 50],
        'post-100' => ['A hundred posts', 'A hundred pages. Take a bow.', 'Publish 100 posts.', '100', 'lilac', 'yours', 100],
        'words-10000' => ['Ten thousand words', 'Ten thousand published words.', 'Publish 10,000 words.', '10k', 'rose', 'yours', 10000],
        'words-50000' => ['Fifty thousand words', 'Fifty thousand words: a short novel.', 'Publish 50,000 words.', '50k', 'sand', 'yours', 50000],
        'words-100000' => ['A hundred thousand words', 'A hundred thousand words. A whole book.', 'Publish 100,000 words.', '100k', 'coral', 'yours', 100000],
        'streak-4' => ['Four weeks running', 'Something published four weeks in a row.', 'Publish something four weeks in a row.', '4wk', 'mint', 'yours', 4],
        'comment-1' => ['First note from a reader', 'Someone wrote back.', 'Your first approved comment.', '✎', 'sky', 'book', 1],
        'comment-100' => ['A hundred notes', 'A hundred approved comments.', 'Reach 100 approved comments.', '100', 'sun', 'book', 100],
        'visits-1000' => ['A thousand visits', 'A thousand page views.', 'Reach 1,000 page views.', '1k', 'lilac', 'book', 1000],
        'visits-10000' => ['Ten thousand visits', 'Ten thousand page views.', 'Reach 10,000 page views.', '10k', 'rose', 'book', 10000],
    ];

    public static function wanted(?User $user): bool
    {
        return $user && (bool) (((array) $user->preferences)['stickers'] ?? true);
    }

    /**
     * Every sticker for this person, earned ones first (newest first), then the
     * next few to aim for.
     *
     * Each sticker: key, title, line, big, tone, scope, earned, at (?Carbon), have, goal.
     */
    public static function for(User $user): Collection
    {
        $mine = self::myPublished($user);
        $words = self::cumulative($mine->map(fn ($e) => [$e['at'], $e['words']]));
        $posts = self::cumulative($mine->map(fn ($e) => [$e['at'], 1]));
        $comments = self::cumulative(rescue(fn () => Comment::where('status', 'approved')->orderBy('created_at')->get(['created_at'])
            ->map(fn ($c) => [Carbon::parse($c->created_at), 1]), collect(), false));
        $visits = self::cumulative(rescue(fn () => DB::table('site_visits')->orderBy('day')->get(['day', 'views'])
            ->map(fn ($v) => [Carbon::parse($v->day), (int) $v->views]), collect(), false));
        $streak = self::streak($mine->pluck('at'));

        $stickers = collect(self::STICKERS)->map(function ($s, $key) use ($posts, $words, $comments, $visits, $streak) {
            [$title, $earnedLine, $waitingLine, $big, $tone, $scope, $goal] = $s;
            [$have, $at] = match (strtok($key, '-')) {
                'post' => self::reached($posts, $goal),
                'words' => self::reached($words, $goal),
                'comment' => self::reached($comments, $goal),
                'visits' => self::reached($visits, $goal),
                'streak' => [$streak['best'], $streak['best'] >= $goal ? $streak['at'] : null],
                default => [0, null],
            };

            return [
                'key' => $key,
                'title' => $title,
                'line' => $at ? $earnedLine : $waitingLine,
                'big' => $big,
                'tone' => $tone,
                'scope' => $scope,
                'earned' => (bool) $at,
                'at' => $at,
                'have' => min($have, $goal),
                'goal' => $goal,
            ];
        })->values();

        // Birthdays: one for each year the book has been open, then the next one to look forward to.
        $born = self::bornOn();
        if ($born) {
            $years = (int) floor($born->diffInYears(now()));
            foreach (range(1, max(1, $years + 1)) as $year) {
                $day = $born->copy()->addYears($year);
                $stickers->push([
                    'key' => 'birthday-'.$year,
                    'title' => $year === 1 ? 'First birthday' : self::ordinal($year).' birthday',
                    'line' => $day->isPast() ? 'The book turned '.$year.'.' : 'The book turns '.$year.' on '.$day->format('j F Y').'.',
                    'big' => (string) $year,
                    'tone' => 'birthday',
                    'scope' => 'book',
                    'earned' => $day->isPast(),
                    'at' => $day->isPast() ? $day : null,
                    'have' => $day->isPast() ? 1 : 0,
                    'goal' => 1,
                ]);
            }
        }

        return $stickers
            ->sortBy(fn ($s) => $s['earned'] ? [0, -$s['at']->timestamp] : [1, 1 - $s['have'] / max(1, $s['goal'])])
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function earned(User $user): Collection
    {
        return self::for($user)->where('earned', true)->values();
    }

    /**
     * Stickers earned recently that this person hasn't seen yet (newest first).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function fresh(User $user): Collection
    {
        if (! self::wanted($user)) {
            return collect();
        }

        $seen = (array) (((array) $user->preferences)[self::SEEN] ?? []);
        $since = now()->subDays(self::FRESH_DAYS);

        return self::earned($user)
            ->filter(fn ($s) => ! in_array($s['key'], $seen, true) && $s['at']->gte($since))
            ->values();
    }

    /** They've seen the celebration: don't show these again. */
    public static function markSeen(User $user, iterable $keys): void
    {
        $preferences = (array) $user->preferences;
        $preferences[self::SEEN] = array_values(array_unique(array_merge((array) ($preferences[self::SEEN] ?? []), collect($keys)->all())));
        $user->forceFill(['preferences' => $preferences])->save();
    }

    // How it's worked out ------------------------------------------------------------

    /**
     * This person's published entries, oldest first, with the day they went out and how many words.
     *
     * @return Collection<int, array{at: Carbon, words: int}>
     */
    protected static function myPublished(User $user): Collection
    {
        return Remember::for('milestones.mine:'.$user->id, function () use ($user) {
            $counts = self::wordCounts();
            $mine = self::myPublishedNow($user, $counts);
            self::keepWordCounts($counts);

            return $mine;
        });
    }

    /**
     * Words in each post, kept between visits: counting every post's words
     * on each visit to the contents page is slow in a big book. Each count
     * is redone when that post's words change.
     */
    public const WORDS_KEY = 'booklet.milestones.words';

    /** @return array{0: array<string, array{0: int, 1: int}>, 1: bool} the kept counts, and whether any changed */
    protected static function wordCounts(): array
    {
        return [(array) rescue(fn () => \Illuminate\Support\Facades\Cache::get(self::WORDS_KEY, []), [], false), false];
    }

    protected static function keepWordCounts(array $counts): void
    {
        if ($counts[1]) {
            rescue(fn () => \Illuminate\Support\Facades\Cache::forever(self::WORDS_KEY, $counts[0]), null, false);
        }
    }

    /** A post's words, from the kept counts when its words haven't changed. */
    protected static function wordsOf(Entry $e, array &$counts): int
    {
        $content = $e->get('content');
        $stamp = crc32(is_string($content) ? $content : (string) json_encode($content));
        $id = (string) $e->id();
        if (($counts[0][$id][0] ?? null) === $stamp) {
            return $counts[0][$id][1];
        }
        $counts[0][$id] = [$stamp, $words = self::words($content)];
        $counts[1] = true;

        return $words;
    }

    protected static function myPublishedNow(User $user, array &$counts): Collection
    {
        return rescue(fn () => Booklet::entries()->all(), collect(), false)
            ->filter(fn (Entry $e) => $e->status() === 'published' && (string) $e->get('author') === (string) $user->id)
            ->reject(fn (Entry $e) => (string) $e->get('translation_of', '') !== '') // a translation isn't a new post
            ->map(function (Entry $e) use (&$counts) {
                return [
                    'at' => $e->date() ?? ($e->lastModified() ? Carbon::parse($e->lastModified()) : now()),
                    'words' => self::wordsOf($e, $counts),
                ];
            })
            ->sortBy(fn ($e) => $e['at']->timestamp)
            ->values();
    }

    /** Words in a field, whether Markdown, HTML or a set of blocks. */
    public static function words(mixed $value): int
    {
        if (is_array($value)) {
            return array_sum(array_map([self::class, 'words'], $value));
        }
        if (! is_string($value) || $value === '') {
            return 0;
        }
        $text = strip_tags(preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\]\([^)]*\)/', '/[#>*_`~\-|]+/'], [' ', ']', ' '], $value));

        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’]*/u', $text);
    }

    /**
     * Running totals: [[day, total so far], …], oldest first.
     *
     * @param  Collection<int, array{0: Carbon, 1: int}>  $steps
     */
    protected static function cumulative(Collection $steps): Collection
    {
        $total = 0;

        return $steps->map(function ($step) use (&$total) {
            $total += $step[1];

            return [$step[0], $total];
        })->values();
    }

    /**
     * How far along, and the day the goal was reached (if it was).
     *
     * @return array{0: int, 1: ?Carbon}
     */
    protected static function reached(Collection $running, int $goal): array
    {
        $hit = $running->first(fn ($r) => $r[1] >= $goal);

        return [(int) ($running->last()[1] ?? 0), $hit ? $hit[0]->copy() : null];
    }

    /**
     * The longest run of weeks (Monday to Sunday) in a row with something
     * published, and the day that run first reached four weeks.
     *
     * @param  Collection<int, Carbon>  $days  oldest first
     * @return array{best: int, at: ?Carbon}
     */
    public static function streak(Collection $days, int $goal = 4): array
    {
        $best = 0;
        $run = 0;
        $last = null; // the last week counted
        $at = null;

        foreach ($days as $day) {
            // Each week as a whole number, Monday to Sunday (counted from Monday 5 January 1970), so thousands of posts are quick.
            $week = intdiv((int) $day->format('U') + $day->getOffset() - 4 * 86400, 7 * 86400);
            if ($last !== null && $week === $last) {
                continue;
            }
            $run = $last !== null && $week === $last + 1 ? $run + 1 : 1;
            $last = $week;
            $best = max($best, $run);
            if (! $at && $run >= $goal) {
                $at = $day->copy();
            }
        }

        return ['best' => $best, 'at' => $at];
    }

    /** The day the book opened: whoever signed up first. */
    protected static function bornOn(): ?Carbon
    {
        $first = rescue(fn () => User::query()->min('created_at'), null, false);

        return $first ? Carbon::parse($first)->startOfDay() : null;
    }

    protected static function ordinal(int $n): string
    {
        $words = [2 => 'Second', 3 => 'Third', 4 => 'Fourth', 5 => 'Fifth', 6 => 'Sixth', 7 => 'Seventh', 8 => 'Eighth', 9 => 'Ninth', 10 => 'Tenth'];

        return $words[$n] ?? $n.(in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th'));
    }
}
