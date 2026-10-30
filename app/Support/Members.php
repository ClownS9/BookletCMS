<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Models\Group;
use App\Models\Reader;

/**
 * Members-only posts and pages: who each one is open to (everyone, signed-in
 * readers, or readers in chosen groups), and whether the person looking can
 * read it. Everyone else gets its title, first lines and a way to sign in;
 * the rest stays out of search, feeds, the content API and plain files.
 *
 * People from the book (signed in to the admin) can read everything: they
 * can open it in the admin anyway.
 */
class Members
{
    public const WHO = 'open_to';

    public const GROUPS = 'open_to_groups';

    public const OPTIONS = [
        'everyone' => 'Everyone',
        'readers' => 'Signed-in readers',
        'groups' => 'Readers in some groups',
    ];

    /** @return array{who: string, groups: list<string>} */
    public static function rule(Entry $entry): array
    {
        $who = (string) $entry->get(self::WHO, '');
        $groups = array_values(array_filter(array_map('strval', (array) $entry->get(self::GROUPS, [])), fn ($g) => $g !== ''));
        if ($who === 'groups' && $groups === []) {
            $who = 'readers'; // groups all gone: still not for everyone
        }

        return ['who' => isset(self::OPTIONS[$who]) ? $who : 'everyone', 'groups' => $who === 'groups' ? $groups : []];
    }

    public static function locked(Entry $entry): bool
    {
        return self::rule($entry)['who'] !== 'everyone';
    }

    /** Can whoever is looking read all of it? */
    public static function canRead(Entry $entry, ?Reader $reader = null): bool
    {
        $rule = self::rule($entry);
        if ($rule['who'] === 'everyone') {
            return true;
        }
        $request = request();
        if ($request->attributes->get('booklet_static')) {
            return false;
        }
        // People from the book, and whoever holds a proof copy's link (made by the book for them).
        if ($request->user() || $request->attributes->has('booklet_proof')) {
            return true;
        }
        $reader ??= Readers::current();
        if (! $reader) {
            return false;
        }
        if ($rule['who'] === 'readers') {
            return true;
        }

        return array_intersect($rule['groups'], self::groupsOf($reader)) !== [];
    }

    /** The groups this reader is in, once a request. */
    public static function groupsOf(Reader $reader): array
    {
        return Remember::for('members.groups.'.$reader->id, fn () => $reader->groupHandles());
    }

    /** "Members", or the groups' names: for stickers on cards and the locked page. */
    public static function label(Entry $entry): ?string
    {
        $rule = self::rule($entry);
        if ($rule['who'] === 'everyone') {
            return null;
        }
        if ($rule['who'] === 'readers') {
            return __('Members');
        }
        $names = array_values(array_intersect_key(Group::choices(), array_flip($rule['groups'])));

        return $names !== [] ? implode(', ', $names) : __('Members');
    }

    /** Is a section with this "Who sees it" shown to whoever is looking? (all, readers, guests) */
    public static function seesSection(string $who): bool
    {
        if ($who === 'all' || $who === '') {
            return true;
        }
        $in = request()->user() !== null || Readers::current() !== null;

        return $who === 'readers' ? $in : ! $in;
    }
}
