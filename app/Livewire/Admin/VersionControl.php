<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Support\VersionControl as Git;
use Livewire\Attributes\Layout;
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

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
    }

    public function toggle(string $setting): void
    {
        abort_unless(request()->user()->isAdmin() && in_array($setting, ['on', 'push', 'media', 'everything'], true) && ! Git::fixedByEnv($setting), 403);
        $now = ! Git::settings()[$setting];

        if ($setting === 'on' && $now && ! Git::isRepo()) {
            $this->addError('git', 'Start a history first (the button above), then switch it on.');

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
        Git::push()
            ? $this->stamp('Sent to the remote.')
            : $this->addError('git', 'Sending didn’t work: '.(Git::status()['push_error'] ?? 'unknown problem'));
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
        ]), 'Version control');
    }
}
