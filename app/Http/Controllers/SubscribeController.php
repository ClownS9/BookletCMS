<?php

namespace App\Http\Controllers;

use App\Booklet\Site;
use App\Support\Newsletter;
use Illuminate\Http\Request;

/**
 * The links in subscription emails: confirming, and leaving (a page with a
 * button, or one click straight from a mail app's own Unsubscribe).
 */
class SubscribeController extends Controller
{
    /** The site as it is for this request (never a copy kept from an earlier one). */
    protected function site(): Site
    {
        return app(Site::class);
    }

    public function confirm(string $token)
    {
        $subscriber = Newsletter::confirm($token);

        return response()->view('site.subscription', [
            'site' => $this->site(),
            'state' => ! $subscriber ? 'unknown' : ($subscriber->unsubscribed_at ? 'left' : 'confirmed'),
            'subscriber' => $subscriber,
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function leaving(string $token)
    {
        $subscriber = Newsletter::byToken($token);

        return response()->view('site.subscription', [
            'site' => $this->site(),
            'state' => ! $subscriber ? 'unknown' : ($subscriber->unsubscribed_at ? 'left' : 'leaving'),
            'subscriber' => $subscriber,
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function leave(Request $request, string $token)
    {
        $subscriber = Newsletter::unsubscribe($token);

        // Mail apps' one-click button posts here and only wants a yes.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response('', $subscriber ? 200 : 404);
        }

        return response()->view('site.subscription', [
            'site' => $this->site(),
            'state' => $subscriber ? 'left' : 'unknown',
            'subscriber' => $subscriber,
        ])->header('X-Robots-Tag', 'noindex');
    }
}
