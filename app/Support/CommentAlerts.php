<?php

namespace App\Support;

use App\Booklet\Site;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\NewComment;
use Illuminate\Support\Facades\Cache;

/**
 * Tell the right people about a new comment: the entry's author, or (when it
 * has none) the admins, if they have "New comments on my entries" on.
 * At most one email per person per entry every ten minutes, and never in the
 * reader's way: it's sent after their page has answered, and a mail problem
 * is only logged.
 */
class CommentAlerts
{
    public static function send(Comment $comment): void
    {
        dispatch(function () use ($comment) {
            rescue(fn () => self::deliver($comment), null, true);
        })->afterResponse();
    }

    public static function deliver(Comment $comment): int
    {
        $entry = $comment->entry();
        if (! $entry) {
            return 0;
        }

        $author = app(Site::class)->author($entry);
        $people = $author ? collect([$author]) : User::query()->where('role', 'admin')->get();

        $sent = 0;
        foreach ($people as $person) {
            if (! $person->email || ! (bool) (((array) $person->preferences)['notify_comments'] ?? true)) {
                continue;
            }
            if (! Cache::add('booklet.comment-alert.'.$person->id.'.'.$entry->id(), true, now()->addMinutes(10))) {
                continue; // told them about this entry a moment ago
            }
            $person->notify(new NewComment($comment, $entry));
            $sent++;
        }

        return $sent;
    }
}
