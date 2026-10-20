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
use Livewire\WithPagination;

/**
 * Readers who get new posts by email: who they are, what went out, and the
 * switch. Editors and admins.
 */
#[Layout('components.layouts.admin')]
#[Title('Subscribers')]
class Subscribers extends Component
{
    use Stamps, WithPagination;

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
        $this->reset('inviteEmail');
        $this->stamp(match ($result) {
            'already' => 'They’re subscribed already.',
            'wait' => 'An invitation went to them a moment ago.',
            default => 'Invitation sent. They’re added once they confirm.',
        });
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
        $this->stamp($n ? 'Sent to '.number_format($n).' '.str('reader')->plural($n).'.' : 'Already sent, or nobody to send it to yet.');
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
            ->latest()
            ->paginate(25);

        return view('livewire.admin.subscribers', [
            'people' => $people,
            'counts' => Newsletter::counts(),
            'on' => Newsletter::enabled(),
            'waitingPosts' => rescue(fn () => Newsletter::due(), collect(), false),
            'sent' => PostEmail::latest('sent_at')->take(8)->get(),
        ]);
    }
}
