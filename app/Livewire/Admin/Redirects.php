<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Models\Redirect;
use App\Support\Redirects as Forwarding;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Redirects: old addresses that forward to new ones. Booklet makes them by
 * itself when a live entry's address changes; you can add your own too
 * (say, links from an old website). Editors and admins only.
 */
#[Layout('components.layouts.admin')]
#[Title('Redirects')]
class Redirects extends Component
{
    use Stamps;

    use \App\Livewire\Concerns\Paged;

    protected function pagedList(): string
    {
        return 'redirects';
    }

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $from = '';

    public string $to = '';

    public bool $temporary = false;

    public function mount(): void
    {
        abort_unless(in_array(request()->user()->level(), ['admin', 'editor'], true), 403);
    }

    public function add(): void
    {
        abort_unless(in_array(request()->user()->level(), ['admin', 'editor'], true), 403);

        $this->validate([
            'from' => ['required', 'string', 'max:500'],
            'to' => ['required', 'string', 'max:500'],
        ], [], ['from' => 'old address', 'to' => 'new address']);

        $from = Forwarding::normalise($this->from);
        $to = preg_match('#^https?://#i', trim($this->to)) ? trim($this->to) : Forwarding::normalise($this->to);

        if ($from === '/') {
            $this->addError('from', __('The home page can’t forward somewhere else.'));

            return;
        }
        if ($from === $to) {
            $this->addError('to', __('That’s the same address. It would go round in circles.'));

            return;
        }
        if (Redirect::where('from_path', $to)->where('to_path', $from)->exists()) {
            $this->addError('to', __('That address already forwards here, so the two would go round in circles.'));

            return;
        }

        Redirect::updateOrCreate(['from_path' => $from], [
            'to_path' => $to,
            'status' => $this->temporary ? 302 : 301,
            'automatic' => false,
            'entry_id' => null,
            'created_by' => request()->user()?->id,
        ]);
        // Anything that pointed at the old address follows it too (no chains).
        if (! str_starts_with($to, 'http')) {
            Redirect::where('to_path', $from)->update(['to_path' => $to]);
        }

        ActivityLog::record('added a redirect from', 'settings', 'redirect', $from, $from.' → '.$to);
        $this->reset('from', 'to', 'temporary');
        $this->stamp('Redirect added.');
    }

    public function remove(int $id): void
    {
        abort_unless(in_array(request()->user()->level(), ['admin', 'editor'], true), 403);

        $redirect = Redirect::findOrFail($id);
        ActivityLog::record('removed a redirect from', 'settings', 'redirect', $redirect->from_path, $redirect->from_path.' → '.$redirect->to_path);
        $redirect->delete();
        $this->stamp('Redirect removed.');
    }

    /** Check every link in published entries now (also done weekly). */
    public function checkLinks(): void
    {
        abort_unless(in_array(request()->user()->level(), ['admin', 'editor'], true), 403);
        @set_time_limit(300);

        $report = \App\Support\LinkCheck::run();
        $n = count($report['broken']);
        $this->stamp($n === 0
            ? 'Checked '.number_format($report['links']).' links. None broken.'
            : 'Checked '.number_format($report['links']).' links. '.($n === 1 ? 'One needs a look.' : $n.' need a look.'));
    }

    /** "Forward it": start a redirect from a missing address. */
    public function forward(string $path): void
    {
        $this->from = \App\Support\Redirects::normalise($path);
        $this->to = '';
        $this->dispatch('focus-redirect-to');
    }

    public function render()
    {
        $redirects = Redirect::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('from_path', 'like', '%'.$this->search.'%')
                ->orWhere('to_path', 'like', '%'.$this->search.'%')))
            ->orderByDesc('updated_at');
        $redirects = $this->pageOf($redirects);

        // Live addresses, to spot a redirect that's hidden by a page, or one that leads nowhere.
        $live = rescue(fn () => Booklet::entries()->all()
            ->filter(fn ($e) => $e->status() === 'published' && $e->uri())
            ->mapWithKeys(fn ($e) => [$e->uri() => $e->title() ?: 'Untitled']), collect(), false);

        return view('livewire.admin.redirects', [
            'redirects' => $redirects,
            'live' => $live,
            'total' => Redirect::count(),
            'hits' => (int) Redirect::sum('hits'),
            'linkReport' => \App\Support\LinkCheck::report(),
        ]);
    }
}
