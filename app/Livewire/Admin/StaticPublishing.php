<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Support\StaticSite;
use App\Support\VersionControl;
use Illuminate\Support\Facades\Process;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Settings › Publish as plain files: the public site as ordinary web pages
 * for GitHub Pages, Netlify or any host. Made in a separate run of Booklet
 * (php booklet publish-static), so it sees the site exactly as a
 * visitor would. Admins only.
 */
#[Layout('components.layouts.admin')]
class StaticPublishing extends Component
{
    use PageTitle;
    use Stamps;

    public string $address = '';

    public string $domain = '';

    /** 'zip' or 'github'. */
    public string $where = 'zip';

    public bool $auto = false;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $settings = StaticSite::settings();
        $this->address = $settings['address'];
        $this->domain = $settings['domain'];
        $this->where = $settings['github'] ? 'github' : 'zip';
        $this->auto = $settings['auto'];
    }

    public function save(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->address = rtrim(trim($this->address), '/');
        $this->domain = strtolower(trim(preg_replace('#^https?://#i', '', trim($this->domain)), '/'));
        $this->validate([
            'address' => ['nullable', 'string', 'max:300', 'regex:#^https?://[^/\s]+(/[^\s]*)?$#i'],
            'domain' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/'],
        ], [
            'address.regex' => 'Start with https:// (for example https://you.github.io/kopi-kuih).',
            'domain.regex' => 'Just the domain, like kopikuih.com.',
        ]);
        if ($this->where === 'github' && ! $this->connected()) {
            $this->where = 'zip';
            $this->addError('github', __('Connect version control to GitHub first.'));
        }

        StaticSite::saveSettings(['address' => $this->address, 'domain' => $this->domain, 'github' => $this->where === 'github', 'auto' => $this->auto]);
        $this->stamp('Saved.');
    }

    protected function connected(): bool
    {
        return VersionControl::isRepo() && VersionControl::remoteUrl() !== null;
    }

    /** Make the files (and send them, when GitHub Pages is on), in a separate run of Booklet. */
    public function publish(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->save();
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $php = (new PhpExecutableFinder)->find(false);
        if (! $php) {
            $this->addError('publish', __('Booklet couldn’t find PHP to run the publishing on this server. Run php booklet publish-static there instead.'));

            return;
        }
        @set_time_limit(660);
        $result = Process::path(base_path())->timeout(600)->run([$php, 'artisan', 'booklet:publish-static', '--no-ansi']);
        $said = trim($result->output()."\n".$result->errorOutput());

        if (! $result->successful()) {
            $this->addError('publish', \Illuminate\Support\Str::limit(trim(preg_replace('/^\s*(ERROR|INFO|WARN)\s*/m', '', $said)) ?: 'Publishing didn’t work.', 400));

            return;
        }
        ActivityLog::record('published the site as plain files', 'settings', 'settings', 'static_site', 'Plain files');
        $last = StaticSite::last();
        $this->stamp(trans_choice('Made :count page.|Made :count pages.', $last['pages'] ?? 0).' '.($this->where === 'github' ? __('Sent to GitHub Pages.') : __('Download the .zip on the right.')));
    }

    public function render()
    {
        $connected = $this->connected();

        return $this->titled(view('livewire.admin.static-publishing', [
            'last' => StaticSite::last(),
            'connected' => $connected,
            'remoteLabel' => $connected ? VersionControl::remoteLabel() : null,
            'suggested' => StaticSite::githubPagesAddress() ?: rtrim((string) (app(\App\Booklet\Site::class)->setting('site_url') ?: config('app.url')), '/'),
            'effective' => StaticSite::address(),
            'pagesSettings' => $connected ? StaticSite::githubPagesSettings() : null,
            'hasZip' => is_dir(StaticSite::folder()),
        ]), 'Publish as plain files');
    }
}
