<?php

namespace App\Http\Controllers;

use App\Booklet\Site;
use App\Models\Group;
use App\Models\Reader;
use App\Support\ReaderAccounts;
use App\Support\Readers;
use Illuminate\Http\Request;

/**
 * The readers' pages on the public site (/members…). Each is a template a
 * theme can replace (site/readers/*.blade.php) around Booklet's own form.
 */
class ReaderController extends Controller
{
    protected function show(string $view, array $data = [])
    {
        abort_unless(Readers::on() || $view === 'site.readers.join', 404);

        return response()->view($view, ['site' => app(Site::class)] + $data)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex');
    }

    /** Their own page (or signing in first). */
    public function home()
    {
        if (! Readers::signedIn()) {
            return redirect()->route('readers.sign-in');
        }

        return $this->show('site.readers.home');
    }

    public function join(Request $request)
    {
        if (Readers::signedIn()) {
            return redirect()->route('readers.home');
        }
        // An invitation from the book: a signed link carrying their address (and groups).
        $invite = $request->hasValidSignature() && $request->filled('email') ? [
            'email' => (string) $request->query('email'),
            'name' => (string) $request->query('name', ''),
            'groups' => array_values(array_filter(explode(',', (string) $request->query('groups', '')))),
            'by' => $request->integer('by') ?: null,
        ] : null;
        abort_if(Readers::joining() === 'off' && ! $invite && ! ReaderAccounts::groupInvite(), 404);

        return $this->show('site.readers.join', ['invite' => $invite]);
    }

    public function signIn(Request $request)
    {
        if (Readers::signedIn()) {
            return redirect()->route('readers.home');
        }
        if ($back = Readers::safeBack((string) $request->query('back', ''))) {
            session()->put('readers.back', $back);
        }

        return $this->show('site.readers.sign-in');
    }

    public function forgot()
    {
        return $this->show('site.readers.forgot');
    }

    public function reset(string $token)
    {
        return $this->show('site.readers.reset', ['token' => $token]);
    }

    /** The button in the confirm email (a signed link). */
    public function confirm(Request $request, Reader $reader, string $hash)
    {
        abort_unless($request->hasValidSignature() && hash_equals(sha1($reader->email), $hash), 403);
        ReaderAccounts::confirm($reader);
        if (! Readers::signedIn()) {
            ReaderAccounts::signIn($reader);
        }

        return redirect()->route('readers.home')->with('readers.confirmed', true);
    }

    public function signOut()
    {
        ReaderAccounts::signOut();

        return redirect('/');
    }

    /** A group's invitation link: in at once when signed in, or after joining or signing in. */
    public function groupInvite(string $group, string $token)
    {
        $model = Group::query()->where('handle', $group)->first();
        abort_unless($model && $model->joining === 'invite' && $model->invite_token && hash_equals($model->invite_token, $token), 404);

        if ($reader = Readers::signedIn()) {
            ReaderAccounts::addTo($model, $reader);

            return redirect()->route('readers.home')->with('readers.joined', $model->name);
        }
        ReaderAccounts::holdGroupInvite($model);

        return $this->show('site.readers.invited', ['group' => $model]);
    }
}
