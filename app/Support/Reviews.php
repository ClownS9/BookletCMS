<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\EntryNote;
use App\Models\User;
use App\Notifications\ReviewNews;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Review before publishing. Writers can't publish, so when a draft is ready
 * they ask for a review: editors and admins hear about it, read it, and
 * either publish it (the writer hears it's live) or send it back with a
 * note (which also sticks to the entry as a team note).
 *
 * Kept on the entry itself: `review` while it waits ({by, at, note}) and
 * `review_back` after it's sent back ({by, at, note}), until asked again.
 */
class Reviews
{
    public const NOTE_MAX = 600;

    /** Can this person publish (and so review)? */
    public static function reviews(User $user, ?Entry $entry = null): bool
    {
        return $entry ? $user->canPublishIn($entry->collectionHandle()) : in_array($user->level(), ['admin', 'editor'], true);
    }

    /** 'waiting', 'returned' or null. */
    public static function state(Entry $entry): ?string
    {
        if ($entry->published()) {
            return null;
        }

        return match (true) {
            is_array($entry->get('review')) => 'waiting',
            is_array($entry->get('review_back')) => 'returned',
            default => null,
        };
    }

    /**
     * What's known about it: who asked or sent it back, when, and the note.
     *
     * @return array{by: ?User, at: ?Carbon, note: string}|null
     */
    public static function details(Entry $entry): ?array
    {
        $raw = match (self::state($entry)) {
            'waiting' => $entry->get('review'),
            'returned' => $entry->get('review_back'),
            default => null,
        };
        if (! is_array($raw)) {
            return null;
        }

        return [
            'by' => isset($raw['by']) ? User::find($raw['by']) : null,
            'at' => isset($raw['at']) ? rescue(fn () => Carbon::parse($raw['at']), null, false) : null,
            'note' => (string) ($raw['note'] ?? ''),
        ];
    }

    /**
     * Drafts waiting for a review, oldest request first.
     *
     * @return Collection<int, Entry>
     */
    public static function waiting(): Collection
    {
        return Booklet::entries()->all()
            ->filter(fn (Entry $e) => self::state($e) === 'waiting')
            ->sortBy(fn (Entry $e) => (string) ($e->get('review')['at'] ?? ''))
            ->values();
    }

    /**
     * This writer's drafts that were sent back.
     *
     * @return Collection<int, Entry>
     */
    public static function returnedTo(User $user): Collection
    {
        return Booklet::entries()->all()
            ->filter(fn (Entry $e) => self::state($e) === 'returned' && (string) $e->get('author') === (string) $user->id)
            ->values();
    }

    /** A writer (or anyone who can edit it) asks for a review. */
    public static function ask(User $user, Entry $entry, string $note = ''): void
    {
        if ($entry->published()) {
            return;
        }

        $data = $entry->data();
        unset($data['review_back']);
        $data['review'] = ['by' => $user->id, 'at' => now()->toIso8601String(), 'note' => self::clean($note)];
        $entry->setData($data)->save();

        Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'asked for a review', (string) $user->id);
        ActivityLog::record('asked for a review of', 'edits', 'entry', $entry->id(), $entry->title());

        // Whoever can publish in this chapter hears about it (an editor-based card that can't, doesn't).
        $reviewers = User::query()->whereIn('role', \App\Models\Role::keysAtLevel(['admin', 'editor']))->whereKeyNot($user->id)->get()
            ->filter(fn (User $u) => $u->canPublishIn($entry->collectionHandle()));
        self::tell($reviewers, new ReviewNews('asked', $entry, $user, self::clean($note)));
    }

    /** Asked by mistake, or not quite ready after all. */
    public static function withdraw(User $user, Entry $entry): void
    {
        $data = $entry->data();
        if (! isset($data['review'])) {
            return;
        }
        unset($data['review']);
        $entry->setData($data)->save();
        ActivityLog::record('took back the review request for', 'edits', 'entry', $entry->id(), $entry->title());
    }

    /** An editor sends it back to the writer, with what to change. */
    public static function sendBack(User $editor, Entry $entry, string $note): void
    {
        $note = self::clean($note);
        $asked = $entry->get('review');

        $data = $entry->data();
        unset($data['review']);
        $data['review_back'] = ['by' => $editor->id, 'at' => now()->toIso8601String(), 'note' => $note];
        $entry->setData($data)->save();

        // The note also sticks to the entry, so it's there while they work.
        EntryNote::create(['entry_id' => $entry->id(), 'user_id' => $editor->id, 'body' => $note, 'tone' => 'rose']);

        Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'sent back', (string) $editor->id);
        ActivityLog::record('sent back for changes:', 'edits', 'entry', $entry->id(), $entry->title());

        self::tell(self::writerOf($entry, $asked)->reject(fn (User $u) => $u->id === $editor->id), new ReviewNews('returned', $entry, $editor, $note));
    }

    /**
     * It's been published or scheduled (from the editor, or by Stacy): the
     * review is over, and whoever asked hears about it.
     */
    public static function settle(Entry $entry, User $by): void
    {
        $data = $entry->data();
        if (! isset($data['review']) && ! isset($data['review_back'])) {
            return;
        }
        $asked = $data['review'] ?? null;
        unset($data['review'], $data['review_back']);
        $entry->setData($data)->save();

        if ($asked) {
            $kind = $entry->status() === 'scheduled' ? 'scheduled' : 'published';
            self::tell(self::writerOf($entry, $asked)->reject(fn (User $u) => $u->id === $by->id), new ReviewNews($kind, $entry, $by));
        }
    }

    /** The people to tell about their draft: whoever asked, and its author. */
    protected static function writerOf(Entry $entry, mixed $asked): Collection
    {
        $ids = array_filter([is_array($asked) ? ($asked['by'] ?? null) : null, $entry->get('author')]);

        return User::query()->whereKey(array_unique($ids))->get();
    }

    /** Email them, if they want these (and never let a mail problem get in the way). */
    protected static function tell(Collection $people, ReviewNews $news): void
    {
        foreach ($people as $person) {
            if (! $person->email || ! (bool) (((array) $person->preferences)['notify_reviews'] ?? true)) {
                continue;
            }
            rescue(fn () => $person->notify($news), null, true);
        }
    }

    protected static function clean(string $note): string
    {
        return Str::limit(trim(preg_replace("/[ \t]+/", ' ', strip_tags($note))), self::NOTE_MAX, '');
    }
}
