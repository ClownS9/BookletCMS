<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Support\VersionControl as Git;
use Livewire\Attributes\Layout;
use Illuminate\Support\Str;
use Livewire\Component;
use RuntimeException;

/**
 * Settings › Version control: every change to the book committed to git on
 * its own (Statamic Pro's Git automation), the history as a timeline, and
 * putting the book back to any point in it. Admins only.
 */
#[Layout('components.layouts.admin')]
class VersionControl extends Component
{
    use PageTitle;
    use Stamps;

    /** A commit opened to see what it changed. */
    public ?string $open = null;

    /** The commit about to be put back (asks first). */
    public ?string $restoring = null;

    public string $message = '';

    public int $shown = 20;

    // Connecting to GitHub ---------------------------------------------------------------

    /** What was pasted: a GitHub address, or "owner/repository". */
    public string $address = '';

    /** How Booklet signs in: 'token' (pasted here) or 'own' (this computer's SSH key or Keychain). */
    public string $signIn = 'token';

    public string $token = '';

    /** Changing the token on a connection that already works. */
    public bool $changingToken = false;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
    }

    public function toggle(string $setting): void
    {
        abort_unless(request()->user()->isAdmin() && in_array($setting, ['on', 'push', 'media', 'everything'], true) && ! Git::fixedByEnv($setting), 403);
        $now = ! Git::settings()[$setting];

        if ($setting === 'on' && $now && ! Git::isRepo()) {
            $this->addError('git', __('Start a history first (the button above), then switch it on.'));

            return;
        }
        Git::saveSettings([$setting => $now]);
        ActivityLog::record(($now ? 'switched on ' : 'switched off ').['on' => 'version control', 'push' => 'sending commits on', 'media' => 'keeping pictures in version control', 'everything' => 'committing the whole project'][$setting], 'settings', 'settings', 'version_control', 'Version control');
        $this->stamp(match ($setting) {
            'on' => $now ? 'On. Changes are committed a couple of minutes after things go quiet.' : 'Off. Nothing more is committed.',
            'push' => $now ? 'Commits will be sent on to the remote.' : 'Commits stay on this server.',
            'everything' => $now ? 'The whole project is committed now: code, the README, screenshots… Never .env, the database, vendor or logs.' : 'Back to the book’s content only.',
            default => $now ? 'Pictures and files are kept in the history too.' : 'Pictures and files are left out of the history.',
        });
    }

    public function startHistory(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        try {
            Git::init();
            Git::saveSettings(['on' => true]);
            $first = Git::commit('The book, as it was when its history began', request()->user());
            ActivityLog::record('started the version history', 'settings', 'settings', 'version_control', 'Version control');
            $this->stamp($first ? 'History started, with everything as it is now.' : 'History started.');
        } catch (RuntimeException $e) {
            $this->addError('git', $e->getMessage());
        }
    }

    public function commitNow(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->validate(['message' => ['nullable', 'string', 'max:100']], [], ['message' => 'message']);
        try {
            $hash = Git::commit($this->message, request()->user());
            $this->message = '';
            $this->stamp($hash ? "Saved to the history ({$hash})." : 'Nothing has changed since the last commit.');
        } catch (RuntimeException $e) {
            $this->addError('git', $e->getMessage());
        }
    }

    public function pushNow(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        if (Git::push()) {
            $this->stamp('Sent to GitHub.');
        } elseif ((Git::status()['push_problem'] ?? null) !== 'diverged') {
            $this->addError('github', Git::status()['push_error'] ?? 'Sending didn’t work.');
        }
    }

    /** The three steps: address, sign-in, then connect and send everything. */
    public function connect(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->resetErrorBag();

        $url = Git::normalizeRemote($this->address);
        if ($url === null) {
            $this->addError('address', str_contains($this->address, '@') && str_starts_with(trim($this->address), 'https://')
                ? 'Paste the address without a name or password in it. The token goes in step 3.'
                : 'That doesn’t look like a repository address. Copy it from the green “Code” button on GitHub, or type owner/repository.');

            return;
        }
        $useToken = $this->signIn === 'token' && str_starts_with($url, 'https://');
        $token = trim($this->token);
        if ($useToken && $token === '' && ! Git::hasToken()) {
            $this->addError('token', __('Paste the token from GitHub here (it starts with github_pat_ or ghp_).'));

            return;
        }
        if ($useToken && $token !== '' && (strlen($token) < 20 || preg_match('/\s/', $token))) {
            $this->addError('token', __('That doesn’t look like a whole token. Copy it again from GitHub.'));

            return;
        }

        try {
            Git::connect($url, $useToken ? ($token !== '' ? $token : null) : '');
        } catch (RuntimeException $e) {
            $this->addError('github', $e->getMessage());

            return;
        }
        $this->token = '';
        $this->address = '';
        ActivityLog::record('connected version control to', 'settings', 'settings', 'version_control', Git::remoteLabel() ?? 'GitHub');

        // Nothing committed yet? The book as it is now goes first.
        rescue(fn () => Git::commit('The book, as it was when it went to GitHub', request()->user()), null, false);

        if (Git::remoteIsEmpty() === false) {
            // Not empty: ask (below) rather than guess.
            Git::push();
            if ((Git::status()['push_problem'] ?? null) === 'diverged') {
                return;
            }
        } elseif (! Git::push()) {
            $this->addError('github', Git::status()['push_error'] ?? 'Sending didn’t work.');

            return;
        }
        Git::saveSettings(['push' => true]);
        $this->stamp('Connected. The book is on GitHub, and every commit goes there from now on.');
    }

    /** GitHub has other commits: send this book's history over them (asked for, with a warning). */
    public function replaceRemote(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        if (Git::push(replace: true)) {
            Git::saveSettings(['push' => true]);
            ActivityLog::record('replaced the copy on GitHub with', 'settings', 'settings', 'version_control', 'this book’s history');
            $this->stamp('Done. GitHub now has this book’s history, and every commit goes there from now on.');
        } else {
            $this->addError('github', Git::status()['push_error'] ?? 'Sending didn’t work.');
        }
    }

    public function saveNewToken(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $token = trim($this->token);
        if (strlen($token) < 20 || preg_match('/\s/', $token)) {
            $this->addError('token', __('Paste the whole token from GitHub.'));

            return;
        }
        $old = Git::hasToken();
        Git::saveToken($token);
        $this->token = '';
        $this->changingToken = false;
        $this->stamp($old ? 'New token saved.' : 'Token saved.');
        if (Git::settings()['push']) {
            $this->pushNow();
        }
    }

    public function disconnect(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $was = Git::remoteLabel();
        Git::disconnect();
        ActivityLog::record('disconnected version control from', 'settings', 'settings', 'version_control', $was ?? 'GitHub');
        $this->stamp('Disconnected. The history is still here; nothing is sent anywhere.');
    }

    /** Private files git kept from before: out of git, still on the server. */
    public function untrackPrivate(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        try {
            $hash = Git::untrackPrivate(request()->user());
            ActivityLog::record('took private files out of', 'settings', 'settings', 'version_control', 'version control');
            $this->stamp($hash ? 'Taken out of git. They’re still on the server, and won’t be committed again.' : 'Nothing to take out.');
            if ($hash && Git::settings()['push']) {
                Git::push();
            }
        } catch (RuntimeException $e) {
            $this->addError('git', $e->getMessage());
        }
    }

    public function abortMerge(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        try {
            Git::abortMerge();
            $this->stamp('The unfinished merge was undone. Files are back to how they were before it.');
        } catch (RuntimeException $e) {
            $this->addError('git', $e->getMessage());
        }
    }

    public function openCommit(string $hash): void
    {
        $this->open = $this->open === $hash ? null : $hash;
        $this->restoring = null;
    }

    public function askRestore(string $hash): void
    {
        $this->restoring = $hash;
    }

    public function restore(): void
    {
        abort_unless(request()->user()->isAdmin() && $this->restoring, 403);
        try {
            $hash = Git::restore($this->restoring, request()->user());
            $this->restoring = null;
            $this->open = null;
            $this->stamp($hash ? 'Put back. That’s a new commit too, so it can be undone the same way.' : 'It was already like that: nothing changed.');
        } catch (RuntimeException $e) {
            $this->addError('git', $e->getMessage());
        }
    }

    public function more(): void
    {
        $this->shown = min(200, $this->shown + 20);
    }

    public function render()
    {
        $installed = Git::installed();
        $repo = $installed && Git::isRepo();

        return $this->titled(view('livewire.admin.version-control', [
            'settings' => Git::settings(),
            'installed' => $installed,
            'repo' => $repo,
            'files' => Git::contentInFiles(),
            'paths' => Git::paths(),
            'wanted' => (array) config('booklet.git.paths'),
            'root' => Git::root(),
            'pending' => $repo ? Git::pending() : [],
            'status' => Git::status(),
            'remotes' => $repo ? Git::remotes() : [],
            'remote' => (string) config('booklet.git.remote', 'origin'),
            'history' => $repo ? Git::history($this->shown + 1) : collect(),
            'openFiles' => $repo && $this->open ? Git::files($this->open) : [],
            'fixed' => ['on' => Git::fixedByEnv('on'), 'push' => Git::fixedByEnv('push')],
            'connected' => $repo && in_array((string) config('booklet.git.remote', 'origin'), Git::remotes(), true),
            'remoteUrl' => $repo ? Git::remoteUrl() : null,
            'webUrl' => $repo ? Git::webUrl() : null,
            'remoteLabel' => $repo ? Git::remoteLabel() : null,
            'tokenHint' => Git::tokenHint(),
            'privateTracked' => $repo ? Git::privateTracked() : [],
            'conflicted' => $repo ? Git::conflicted() : [],
            'merging' => $repo && Git::merging(),
            'newRepoUrl' => 'https://github.com/new?'.http_build_query(['name' => Str::slug(app(\App\Booklet\Site::class)->name()) ?: 'my-booklet', 'description' => 'Content of '.app(\App\Booklet\Site::class)->name().', kept by Booklet', 'visibility' => 'private']),
        ]), 'Version control');
    }
}
