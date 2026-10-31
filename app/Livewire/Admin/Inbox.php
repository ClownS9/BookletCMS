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
 * The Inbox: messages from the contact form on the Contact page, and answers
 * to your own forms (Comments › Forms), all together or one form's. Read them,
 * write back (by email, through the book's email settings), archive or
 * mark as spam. Admins and editors.
 */
#[Layout('components.layouts.admin')]
#[Title('Inbox')]
class Inbox extends Component
{
    use \App\Livewire\Concerns\Stamps;

    use \App\Livewire\Concerns\Paged;

    protected function pagedList(): string
    {
        return 'inbox';
    }

    protected function pageResetsOn(): array
    {
        return ['search', 'show', 'from'];
    }

    #[Url(as: 'show', except: 'inbox')]
    public string $show = 'inbox';

    #[Url(as: 'message', except: null)]
    public ?string $selected = null;

    /** One form's letters only (its handle), or every form's. */
    #[Url(as: 'from', except: '')]
    public string $from = '';

    public string $search = '';

    public string $reply = '';

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
        if (! in_array($this->show, ['inbox', 'archived', 'spam'], true)) {
            $this->show = 'inbox';
        }
        if (! array_key_exists($this->from, Letters::forms())) {
            $this->from = '';
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

    public function updatedFrom(): void
    {
        if (! array_key_exists($this->from, Letters::forms())) {
            $this->from = '';
        }
        $this->selected = null;
        $this->reply = '';
    }

    public function updatedShow(): void
    {
        $this->ticked = [];
        $this->selected = null;
        $this->reply = '';
    }

    // The Dashboard's table ----------------------------------------------------

    /** @var list<string> messages ticked in the table */
    public array $ticked = [];

    public function closeDrawer(): void
    {
        $this->selected = null;
        $this->reply = '';
    }

    public function tickAll(array $ids): void
    {
        $ids = array_values(array_filter($ids, 'is_string'));
        $this->ticked = array_diff($ids, $this->ticked) === []
            ? array_values(array_diff($this->ticked, $ids))
            : array_values(array_unique(array_merge($this->ticked, $ids)));
    }

    /** Archive, spam, back to the inbox, mark read, or delete every ticked message. */
    public function doTicked(string $what): void
    {
        abort_unless($this->allowed() && in_array($what, ['archived', 'spam', 'read', 'new', 'delete'], true), 403);
        $n = 0;
        foreach (array_slice(array_filter($this->ticked, 'is_string'), 0, 500) as $id) {
            if (! $m = Letters::find($id)) {
                continue;
            }
            if ($what === 'delete') {
                Letters::delete($m);
                ActivityLog::record($m->form() === Letters::FORM ? 'deleted a message from the contact form' : 'deleted an answer to “'.Letters::formName($m).'”', 'comments', 'message', (string) $m->id(), null);
            } else {
                Letters::setStatus($m, $what);
            }
            $n++;
        }
        $this->ticked = [];
        $this->selected = null;
        $this->stamp(match ($what) {
            'archived' => trans_choice(':count archived.|:count archived.', $n),
            'spam' => trans_choice(':count moved to Spam.|:count moved to Spam.', $n),
            'new' => trans_choice(':count marked unread.|:count marked unread.', $n),
            'delete' => trans_choice(':count deleted.|:count deleted.', $n),
            default => trans_choice(':count back in the inbox.|:count back in the inbox.', $n),
        });
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
                'spam' => __('Moved to Spam. It’s forgotten after :days days.', ['days' => Letters::SPAM_DAYS]),
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
            ActivityLog::record($m->form() === Letters::FORM ? 'deleted a message from the contact form' : 'deleted an answer to “'.Letters::formName($m).'”', 'comments', 'message', (string) $m->id(), null);
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
            $this->addError('reply', __('They didn’t leave an email address, so there’s nowhere to send it.'));

            return;
        }

        $key = 'inbox-reply|'.request()->user()?->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('reply', __('That’s a lot of replies in a short time. Wait a few minutes.'));

            return;
        }
        RateLimiter::hit($key, 600);

        try {
            Letters::reply($m, request()->user(), $this->reply);
        } catch (Throwable $e) {
            report($e);
            $this->addError('reply', __('It didn’t send: :why', ['why' => Str::limit($e->getMessage(), 160)]).(request()->user()->isAdmin() ? ' '.__('Check Settings › Email.') : ''));

            return;
        }

        ActivityLog::record('wrote back to', 'comments', 'message', (string) $m->id(), Letters::sender($m)[0]);
        $this->reply = '';
        $this->stamp(__('Sent to :email.', ['email' => Letters::sender($m)[1]]));
    }

    public function toggleOpen(): void
    {
        abort_unless($this->allowed(), 403);
        Letters::setOpen(! Letters::open());
        $this->stamp(Letters::open() ? 'The form is on the Contact page.' : 'The form is hidden. Nothing new will arrive.');
    }

    public function render()
    {
        $all = Letters::all($this->from !== '' ? $this->from : null);
        $counts = Letters::counts($all);
        $forms = Letters::forms();

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
        $pile = $this->pageOf($pile);

        $chosen = $this->chosen();
        $contact = app(Site::class)->contactPage();

        return view(\App\Support\YourBook::desk() ? 'livewire.admin.inbox-table' : 'livewire.admin.inbox', [
            'pile' => $pile,
            'counts' => $counts,
            'chosen' => $chosen,
            'open' => Letters::open(),
            'contact' => $contact,
            'mailOff' => MailSettings::status()['state'] === 'off',
            'forms' => $forms,
            'replies' => $chosen ? collect((array) $chosen->get('_replies', []))->map(fn ($r) => [
                'by' => \App\Models\User::find($r['by'] ?? null)?->name ?? 'Someone',
                'at' => rescue(fn () => \Illuminate\Support\Carbon::parse($r['at'] ?? null), null, false),
                'body' => (string) ($r['body'] ?? ''),
            ]) : collect(),
        ]);
    }
}
