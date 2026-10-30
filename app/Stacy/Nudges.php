<?php

namespace App\Stacy;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stacy's suggestions: small, kind nudges about things that slip (a draft
 * gone quiet, pictures without descriptions, posts without search text).
 * Shown on Contents to people with "Stacy's suggestions" on. "Not now"
 * hides a nudge for a week.
 */
class Nudges
{
    public const QUIET_DAYS = 14;

    public static function wanted(?User $user): bool
    {
        return $user && (bool) (((array) $user->preferences)['stacy_suggestions'] ?? true);
    }

    /**
     * @return array<int, array{key: string, text: string, label: string, href: string}>
     */
    public static function for(User $user, int $limit = 3): array
    {
        if (! self::wanted($user)) {
            return [];
        }

        $entries = rescue(fn () => Booklet::entries()->all(), collect(), false);
        $arranges = in_array($user->level(), ['admin', 'editor'], true);
        $nudges = [];

        // Admins: something on the server that stops the book working (first, it matters most).
        if ($user->isAdmin()) {
            $fail = (array) (rescue(fn () => \App\Support\Health::summary(), [], false)['fail'] ?? []);
            if ($fail) {
                $nudges[] = [
                    'key' => 'health-'.md5(implode('|', $fail)),
                    'text' => trans_choice('The server check-up found something to fix: :what.|The server check-up found :count things to fix: :what.', count($fail), ['what' => Str::lower(implode(', ', array_map(fn ($f) => __($f), array_slice($fail, 0, 3))))]),
                    'label' => 'See how',
                    'href' => route('admin.settings.health'),
                ];
            }
        }

        // A draft of theirs that's gone quiet.
        $quiet = $entries
            ->filter(fn (Entry $e) => $e->status() === 'draft' && (string) $e->get('author') === (string) $user->id
                && \App\Support\Reviews::state($e) !== 'waiting' // with an editor: nothing for them to do
                && $e->lastModified() && Carbon::parse($e->lastModified())->lt(now()->subDays(self::QUIET_DAYS)))
            ->sortBy(fn (Entry $e) => (string) $e->lastModified())
            ->first();
        if ($quiet) {
            $nudges[] = [
                'key' => 'quiet-'.$quiet->id(),
                'text' => __('“:title” hasn’t been touched in :time. Finish it, or let it go?', [
                    'title' => Str::limit($quiet->title() ?: __('Untitled'), 50),
                    'time' => Carbon::parse($quiet->lastModified())->diffForHumans(['parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]),
                ]),
                'label' => 'Open the draft',
                'href' => route('admin.entries.edit', [$quiet->collectionHandle(), $quiet->id()]),
            ];
        }

        // Pictures nobody has described (screen readers and search need it).
        $noAlt = rescue(fn () => Booklet::assets()->all()
            ->filter(fn ($a) => ! $a->get('alt') && preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $a->path()))->count(), 0, false);
        if ($noAlt) {
            $nudges[] = [
                'key' => 'alt',
                'text' => trans_choice('One picture has no description yet.|:count pictures have no description yet. I can write them for you.', $noAlt),
                'label' => 'Media',
                'href' => route('admin.media'),
            ];
        }

        if ($arranges) {
            // Published, but nothing for search results to show.
            $noMeta = $entries->filter(fn (Entry $e) => $e->status() === 'published' && ! trim((string) $e->get('meta_description')))->count();
            if ($noMeta) {
                $nudges[] = [
                    'key' => 'meta',
                    'text' => $noMeta === 1 ? 'A published entry has no search description.' : $noMeta.' published entries have no search description. I can suggest them.',
                    'label' => 'Ask Stacy',
                    'href' => route('admin.stacy', ['q' => 'Which published entries have no search description? Suggest one for each.']),
                ];
            }

            // Links in published entries that lead nowhere (from the weekly check).
            $broken = rescue(fn () => \App\Support\LinkCheck::broken(), [], false);
            if ($broken) {
                $posts = collect($broken)->pluck('entry_id')->unique()->count();
                $nudges[] = [
                    'key' => 'links-'.md5(implode('|', array_column($broken, 'url'))),
                    'text' => count($broken) === 1
                        ? 'A link in “'.Str::limit($broken[0]['title'], 40).'” leads nowhere.'
                        : count($broken).' links in '.$posts.' '.($posts === 1 ? 'entry' : 'entries').' lead nowhere.',
                    'label' => 'See them',
                    'href' => route('admin.redirects').'#broken-links',
                ];
            }

            // A live page readers can't find from the menu.
            $notInMenu = rescue(fn () => $entries->filter(fn (Entry $e) => $e->collectionHandle() === 'pages' && $e->status() === 'published'
                && ! \App\Booklet\Support\Menus::has('header', $e->id()))->count(), 0, false);
            if ($notInMenu) {
                $nudges[] = [
                    'key' => 'menu',
                    'text' => $notInMenu === 1 ? 'A published page isn’t in the site menu.' : $notInMenu.' published pages aren’t in the site menu.',
                    'label' => 'Site menus',
                    'href' => route('admin.menus'),
                ];
            }
        }

        return array_slice(array_values(array_filter($nudges, fn ($n) => ! Cache::has(self::hiddenKey($user, $n['key'])))), 0, $limit);
    }

    /** "Not now": hide this nudge for a week. */
    public static function hide(User $user, string $key): void
    {
        Cache::put(self::hiddenKey($user, $key), true, now()->addWeek());
    }

    protected static function hiddenKey(User $user, string $key): string
    {
        return 'booklet.nudge.'.$user->id.'.'.md5($key);
    }
}
