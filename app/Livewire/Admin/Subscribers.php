<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Models\PostEmail;
use App\Models\Subscriber;
use App\Support\Newsletter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Readers who get new posts by email: who they are, what went out, and the
 * switch. Editors and admins.
 */
#[Layout('components.layouts.admin')]
#[Title('Subscribers')]
class Subscribers extends Component
{
    use Stamps;

    use \App\Livewire\Concerns\Paged;

    protected function pagedList(): string
    {
        return 'subscribers';
    }

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'subscribed')]
    public string $show = 'subscribed';

    public string $inviteEmail = '';

    public function mount(): void
    {
        $this->authorise();
    }

    protected function authorise(): void
    {
        abort_unless(in_array(request()->user()->level(), ['admin', 'editor'], true), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShow(): void
    {
        $this->ticked = [];
        $this->resetPage();
    }

    public function toggle(): void
    {
        $this->authorise();
        $on = ! Newsletter::enabled();
        Newsletter::setEnabled($on);
        ActivityLog::record($on ? 'turned on emails to subscribers' : 'turned off emails to subscribers', 'settings', 'setting', 'newsletter', 'New posts by email');
        $this->stamp($on ? 'New posts will go out by email again (from now on).' : 'Paused. New posts won’t be emailed.');
    }

    /** Invite someone: they get the same "confirm" email as signing up on the site. */
    public function invite(): void
    {
        $this->authorise();
        $this->inviteEmail = mb_strtolower(trim($this->inviteEmail));
        $this->validate(['inviteEmail' => ['required', 'email:rfc', 'max:190']], [], ['inviteEmail' => 'email address']);

        $result = Newsletter::subscribe($this->inviteEmail, null, 'invited');
        ActivityLog::record('invited a subscriber', 'people', 'subscriber', $this->inviteEmail, $this->inviteEmail);
        $this->reset('inviteEmail', 'inviting');
        $this->stamp(match ($result) {
            'already' => 'They’re subscribed already.',
            'wait' => 'An invitation went to them a moment ago.',
            default => 'Invitation sent. They’re added once they confirm.',
        });
    }

    // The Dashboard's table ----------------------------------------------------

    /** @var list<int> subscribers ticked in the table */
    public array $ticked = [];

    public bool $inviting = false;

    public function tickAll(array $ids): void
    {
        $ids = array_values(array_map('intval', array_filter($ids, 'is_numeric')));
        $this->ticked = array_diff($ids, $this->ticked) === []
            ? array_values(array_diff($this->ticked, $ids))
            : array_values(array_unique(array_merge($this->ticked, $ids)));
    }

    public function removeTicked(): void
    {
        $this->authorise();
        $n = 0;
        foreach (Subscriber::query()->whereIn('id', array_slice(array_map('intval', $this->ticked), 0, 500))->get() as $subscriber) {
            ActivityLog::record('removed a subscriber', 'people', 'subscriber', (string) $subscriber->id, $subscriber->email);
            $subscriber->delete();
            $n++;
        }
        $this->ticked = [];
        $this->stamp(trans_choice(':count removed, and the address forgotten.|:count removed, and their addresses forgotten.', $n));
    }

    /** Take someone off the list entirely (their address is forgotten). */
    public function remove(int $id): void
    {
        $this->authorise();
        $subscriber = Subscriber::find($id);
        if ($subscriber) {
            ActivityLog::record('removed a subscriber', 'people', 'subscriber', (string) $id, $subscriber->email);
            $subscriber->delete();
            $this->stamp('Removed, and their address forgotten.');
        }
    }

    /** Send a post that's waiting now, instead of after the usual pause. */
    public function sendNow(string $entryId): void
    {
        $this->authorise();
        $entry = Booklet::entries()->find($entryId);
        if (! $entry || $entry->status() !== 'published' || ! Newsletter::enabled()) {
            return;
        }
        @set_time_limit(300);
        $n = Newsletter::send($entry);
        $this->stamp($n ? trans_choice('Sent to :count reader.|Sent to :count readers.', $n, ['count' => number_format($n)]) : __('Already sent, or nobody to send it to yet.'));
    }

    /** "Don't email this one." */
    public function skip(string $entryId): void
    {
        $this->authorise();
        $entry = Booklet::entries()->find($entryId);
        if ($entry) {
            PostEmail::firstOrCreate(['entry_id' => $entry->id()], ['title' => $entry->title(), 'status' => 'skipped', 'sent_at' => now()]);
            $this->stamp('That one won’t be emailed.');
        }
    }

    public function render()
    {
        $people = Subscriber::query()
            ->when($this->show === 'subscribed', fn ($q) => $q->active())
            ->when($this->show === 'waiting', fn ($q) => $q->whereNull('confirmed_at')->whereNull('unsubscribed_at'))
            ->when($this->show === 'unsubscribed', fn ($q) => $q->whereNotNull('unsubscribed_at'))
            ->when($this->search !== '', fn ($q) => $q->where('email', 'like', '%'.mb_strtolower($this->search).'%'))
            ->latest();
        $people = $this->pageOf($people);

        return view(\App\Support\YourBook::desk() ? 'livewire.admin.subscribers-table' : 'livewire.admin.subscribers', [
            'people' => $people,
            'counts' => Newsletter::counts(),
            'on' => Newsletter::enabled(),
            'waitingPosts' => rescue(fn () => Newsletter::due(), collect(), false),
            'sent' => PostEmail::latest('sent_at')->take(8)->get(),
        ]);
    }
}
