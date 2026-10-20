<?php

namespace App\Support;

use App\Models\Comment;
use App\Notifications\CommentReply;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * When a reply goes up under a note, email the people in that conversation
 * who asked to hear about replies (the note's writer, and anyone who
 * replied with the box ticked) — never the one who just wrote, and at most
 * one email per person per conversation every ten minutes. Sent after the
 * page has answered; a mail problem is only logged.
 */
class ReplyAlerts
{
    public static function send(Comment $reply): void
    {
        // Marked first, so saving it again (or a second "approve") never sends twice.
        $reply->forceFill(['alerted_at' => now()])->saveQuietly();

        // Nobody in this conversation asked to hear (imports, most notes): nothing to do.
        $rootId = $reply->parent_id ?? $reply->id;
        if (! Comment::query()->where(fn ($q) => $q->whereKey($rootId)->orWhere('parent_id', $rootId))->where('notify', true)->exists()) {
            return;
        }

        dispatch(function () use ($reply) {
            rescue(fn () => self::deliver($reply), null, true);
        })->afterResponse();
    }

    /** @return int how many emails went */
    public static function deliver(Comment $reply): int
    {
        $root = $reply->root();
        $entry = $reply->entry();
        if (! $entry || $entry->status() !== 'published') {
            return 0;
        }

        $writer = mb_strtolower((string) ($reply->author_email ?: $reply->user?->email));
        $people = collect([$root])
            ->merge($root->replies()->where('status', 'approved')->where('id', '!=', $reply->id)->get())
            ->filter(fn (Comment $c) => $c->notify && $c->author_email)
            ->unique(fn (Comment $c) => mb_strtolower($c->author_email))
            ->reject(fn (Comment $c) => mb_strtolower($c->author_email) === $writer);

        $sent = 0;
        foreach ($people as $c) {
            $email = mb_strtolower($c->author_email);
            if (! Cache::add('booklet.reply-alert.'.$root->id.'.'.md5($email), true, now()->addMinutes(10))) {
                continue; // told them about this conversation a moment ago
            }
            Notification::route('mail', [$email => $c->author_name])
                ->notify(new CommentReply($reply, $entry, $c->is($root), self::stopUrl($root, $email)));
            $sent++;
        }

        return $sent;
    }

    /** "Stop emails about this conversation", for one address (the address itself isn't in the link). */
    public static function stopUrl(Comment $root, string $email): string
    {
        return URL::signedRoute('site.conversation.stop', ['comment' => $root->id, 'who' => self::who($email)]);
    }

    public static function who(string $email): string
    {
        return substr(hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key')), 0, 20);
    }

    /** Stop the emails to this address about this conversation. Returns how many notes it changed. */
    public static function stop(Comment $root, string $who): int
    {
        $stopped = 0;
        foreach (collect([$root])->merge($root->replies) as $c) {
            if ($c->notify && $c->author_email && hash_equals(self::who($c->author_email), $who)) {
                $c->forceFill(['notify' => false])->saveQuietly();
                $stopped++;
            }
        }

        return $stopped;
    }
}
