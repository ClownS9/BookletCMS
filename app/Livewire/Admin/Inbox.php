<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Submission;
use App\Booklet\Site;
use App\Models\ActivityLog;
use App\Support\Inbox as Letters;
use App\Support\MailSettings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * The Inbox: messages from the contact form on the Contact page. Read them,
 * write back (by email, through the book's email settings), archive or
 * mark as spam. Admins and editors.
 */
#[Layout('components.layouts.admin')]
#[Title('Inbox')]
class Inbox extends Component
{
    use \App\Livewire\Concerns\Stamps;

    #[Url(as: 'show', except: 'inbox')]
    public string $show = 'inbox';

    #[Url(as: 'message', except: null)]
    public ?string $selected = null;

    public string $search = '';

    public string $reply = '';

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
        if (! in_array($this->show, ['inbox', 'archived', 'spam'], true)) {
            $this->show = 'inbox';
        }
        if ($this->selected && ($m = Letters::find($this->selected))) {
            Letters::markRead($m); // opened from the email
            $this->show = match (Letters::status($m)) { 'archived' => 'archived', 'spam' => 'spam', default => 'inbox' };
        }
    }

    protected function allowed(): bool
    {
        return in_array(request()->user()?->level(), ['admin', 'editor'], true);
    }

    public function updatedShow(): void
    {
        $this->selected = null;
        $this->reply = '';
    }

    public function pick(string $id): void
    {
        abort_unless($this->allowed(), 403);
        $m = Letters::find($id);
        if ($m) {
            Letters::markRead($m);
            $this->selected = $id;
            $this->reply = '';
            $this->resetErrorBag();
        }
    }

    protected function chosen(): ?Submission
    {
        return $this->selected ? Letters::find($this->selected) : null;
    }

    public function setChosenStatus(string $status): void
    {
        abort_unless($this->allowed() && in_array($status, ['read', 'new', 'archived', 'spam'], true), 403);
        if ($m = $this->chosen()) {
            Letters::setStatus($m, $status);
            $this->stamp(match ($status) {
                'archived' => 'Archived.',
                'spam' => 'Moved to Spam. It’s forgotten after '.Letters::SPAM_DAYS.' days.',
                'new' => 'Marked unread.',
                default => 'Back in the inbox.',
            });
            if ($status !== 'new') {
                $this->selected = null; // on to the next one
            }
        }
    }

    public function deleteChosen(): void
    {
        abort_unless($this->allowed(), 403);
        if ($m = $this->chosen()) {
            Letters::delete($m);
            ActivityLog::record('deleted a message from the contact form', 'comments', 'message', (string) $m->id(), null);
            $this->selected = null;
            $this->stamp('Deleted.');
        }
    }

    public function sendReply(): void
    {
        $this->resetErrorBag();
        abort_unless($this->allowed(), 403);
        $m = $this->chosen();
        if (! $m) {
            return;
        }

        $this->reply = trim($this->reply);
        $this->validate(['reply' => ['required', 'string', 'max:'.Letters::MESSAGE_MAX]], [
            'reply.required' => 'Write something to send.',
        ], ['reply' => 'reply']);

        if (! Letters::sender($m)[1]) {
            $this->addError('reply', 'They didn’t leave an email address, so there’s nowhere to send it.');

            return;
        }

        $key = 'inbox-reply|'.request()->user()?->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('reply', 'That’s a lot of replies in a short time. Wait a few minutes.');

            return;
        }
        RateLimiter::hit($key, 600);

        try {
            Letters::reply($m, request()->user(), $this->reply);
        } catch (Throwable $e) {
            report($e);
            $this->addError('reply', 'It didn’t send: '.Str::limit($e->getMessage(), 160).(request()->user()->isAdmin() ? ' Check Settings › Email.' : ''));

            return;
        }

        ActivityLog::record('wrote back to', 'comments', 'message', (string) $m->id(), Letters::sender($m)[0]);
        $this->reply = '';
        $this->stamp('Sent to '.Letters::sender($m)[1].'.');
    }

    public function toggleOpen(): void
    {
        abort_unless($this->allowed(), 403);
        Letters::setOpen(! Letters::open());
        $this->stamp(Letters::open() ? 'The form is on the Contact page.' : 'The form is hidden. Nothing new will arrive.');
    }

    public function render()
    {
        $all = Letters::all();
        $counts = Letters::counts($all);

        $pile = $all
            ->filter(fn (Submission $s) => match ($this->show) {
                'archived' => Letters::status($s) === 'archived',
                'spam' => Letters::status($s) === 'spam',
                default => in_array(Letters::status($s), ['new', 'read'], true),
            })
            ->when(trim($this->search) !== '', fn ($c) => $c->filter(fn (Submission $s) => Str::contains(
                Str::lower(implode(' ', array_filter($s->data(), 'is_scalar'))), Str::lower(trim($this->search))
            )))
            ->values();

        $chosen = $this->chosen();
        $contact = app(Site::class)->contactPage();

        return view('livewire.admin.inbox', [
            'pile' => $pile,
            'counts' => $counts,
            'chosen' => $chosen,
            'open' => Letters::open(),
            'contact' => $contact,
            'mailOff' => MailSettings::status()['state'] === 'off',
            'replies' => $chosen ? collect((array) $chosen->get('_replies', []))->map(fn ($r) => [
                'by' => \App\Models\User::find($r['by'] ?? null)?->name ?? 'Someone',
                'at' => rescue(fn () => \Illuminate\Support\Carbon::parse($r['at'] ?? null), null, false),
                'body' => (string) ($r['body'] ?? ''),
            ]) : collect(),
        ]);
    }
}
