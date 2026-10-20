<?php

namespace App\Http\Controllers;

use App\Booklet\Site;
use App\Models\Comment;
use App\Support\ReplyAlerts;
use Illuminate\Http\Request;

/**
 * "Stop emails about this conversation", from a reply email. The link is
 * signed (and names no email address); opening it asks first, so a mail
 * scanner following links can't stop anything. Mail apps' one-click
 * unsubscribe posts straight here.
 */
class ConversationController extends Controller
{
    public function __invoke(Request $request, string $comment)
    {
        $root = ctype_digit($comment) ? Comment::find((int) $comment) : null;
        $valid = $root && $request->hasValidSignature() && is_string($request->query('who'));

        if ($request->isMethod('post') && $request->input('List-Unsubscribe') === 'One-Click') {
            if ($valid) {
                ReplyAlerts::stop($root, $request->query('who'));
            }

            return response('', $valid ? 200 : 404);
        }

        $state = ! $valid ? 'unknown' : ($request->isMethod('post') ? 'stopped' : 'stopping');
        if ($state === 'stopped') {
            ReplyAlerts::stop($root, $request->query('who'));
        }

        return response()->view('site.conversation', [
            'site' => app(Site::class),
            'state' => $state,
            'entry' => $root?->entry(),
            'root' => $root,
        ])->header('X-Robots-Tag', 'noindex');
    }
}
