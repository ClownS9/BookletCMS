<?php

namespace App\Support;

use Closure;

/**
 * Worked out once a request: anything read from every entry (the stickers,
 * the most recent edits) that several parts of one page ask for. Forgotten
 * as soon as anything in the book changes, so a page that saves and then
 * reads sees what it just saved.
 *
 *   Remember::for('milestones:'.$user->id, fn () => …)
 */
class Remember
{
    public const BINDING = 'booklet.remember';

    public static function for(string $key, Closure $work): mixed
    {
        $kept = app(self::BINDING);
        if (! $kept->offsetExists($key)) {
            $kept[$key] = $work();
        }

        return $kept[$key];
    }

    /** After anything is saved or deleted. */
    public static function forget(): void
    {
        if (app()->resolved(self::BINDING)) {
            app(self::BINDING)->exchangeArray([]);
        }
    }
}
