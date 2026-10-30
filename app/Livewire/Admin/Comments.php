<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\Comment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use App\Livewire\Concerns\Stamps;
use Livewire\Component;

/**
 * Comments: notes readers leave in the margin. New ones wait here until
 * someone approves them; replies are posted as the signed-in person.
 *
 * Worked out when asked (#[Computed]):
 *
 * @property-read mixed $pile
 * @property-read Comment|null $chosen
 */
#[Layout('components.layouts.admin')]
#[Title('Comments')]
class Comments extends Component
{
    use Stamps;

    use \App\Livewire\Concerns\Paged;

    protected function pagedList(): string
    {
        return 'comments';
    }

    public const STATUSES = ['waiting' => 'Waiting', 'approved' => 'Approved', 'spam' => 'Spam'];

    #[Url(except: 'waiting')]
    public string $status = 'waiting';

    #[Url(as: 'comment', except: null)]
    public ?int $selected = null;

    public string $reply = '';

    #[Computed]
    public function pile()
    {
        // Notes, and readers' replies until they're approved (then they live in their conversation).
        $pile = Comment::query()
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhere('status', '!=', 'approved'))
            ->where('status', $this->status)
            ->with('parent')
            ->latest();

        return $this->pageOf($pile);
    }

    #[Computed]
    public function chosen(): ?Comment
    {
        $comment = $this->selected ? Comment::with('replies.user', 'parent')->find($this->selected) : null;

        if ($comment && $comment->status === $this->status && ($comment->parent_id === null || $comment->status !== 'approved')) {
            return $comment;
        }

        return $this->pile->first()?->load('replies.user', 'parent');
    }

    public function updatedStatus(): void
    {
        $this->selected = null;
        $this->reply = '';
        unset($this->pile, $this->chosen);
    }

    public function pick(int $id): void
    {
        $this->selected = $id;
        $this->reply = '';
        unset($this->chosen);
    }

    /** Open any note (a waiting reply, say), in whichever pile it's in. */
    public function open(int $id): void
    {
        $comment = Comment::find($id);
        if ($comment) {
            if ($this->status !== $comment->status) {
                $this->page = 1;
            }
            $this->status = $comment->status;
            $this->selected = $comment->id;
            $this->reply = '';
            unset($this->pile, $this->chosen);
        }
    }

    public function setChosenStatus(string $status): void
    {
        abort_unless(array_key_exists($status, self::STATUSES), 422);

        $comment = $this->chosen;
        if (! $comment) {
            return;
        }

        $comment->update(['status' => $status]);
        ActivityLog::record(
            $status === 'approved' ? 'approved a comment on' : ($status === 'spam' ? 'marked a comment as spam on' : 'moved a comment back to waiting on'),
            'comments', 'comment', (string) $comment->id, $comment->entry()?->title(),
        );

        // Move on to the next note in the same pile.
        $this->selected = null;
        unset($this->pile, $this->chosen);
        $this->stamp(['approved' => 'Approved — it’s on the site now.', 'spam' => 'Moved to spam.', 'waiting' => 'Back in the waiting pile.'][$status]);
    }

    public function sendReply(): void
    {
        $comment = $this->chosen;
        if (! $comment) {
            return;
        }

        $this->validate(['reply' => ['required', 'string', 'max:5000']], [], ['reply' => 'reply']);

        // Writing back to a reply joins the same conversation.
        $comment->root()->replies()->create([
            'entry_id' => $comment->entry_id,
            'user_id' => request()->user()?->id,
            'author_name' => request()->user()->byline ?: request()->user()->name,
            'author_email' => request()->user()->email,
            'body' => $this->reply,
            'status' => 'approved',
        ]);

        // Replying to a waiting note approves it too, like most blogs do.
        if ($comment->status === 'waiting') {
            $comment->update(['status' => 'approved']);
        }

        ActivityLog::record('replied to a comment on', 'comments', 'comment', (string) $comment->id, $comment->entry()?->title());

        $this->reply = '';
        unset($this->pile, $this->chosen);
        $root = $comment->root();
        $hears = collect([$root])->merge($root->replies)->contains(fn ($c) => $c->notify && $c->author_email && mb_strtolower($c->author_email) !== mb_strtolower((string) request()->user()->email));
        $this->stamp($hears ? 'Reply posted. They asked to hear about replies, so it’s on its way by email too.' : 'Reply posted.');
    }

    public function deleteChosen(): void
    {
        $comment = $this->chosen;
        if (! $comment) {
            return;
        }

        // Into the bin for 30 days (with its replies), with Undo.
        $binned = \App\Support\Bin::comment($comment);

        $this->selected = null;
        unset($this->pile, $this->chosen);
        $this->stamp('Moved to the bin.', undo: $binned->id);
    }

    public function render()
    {
        $counts = Comment::query()->where(fn ($q) => $q->whereNull('parent_id')->orWhere('status', '!=', 'approved'))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $chosen = $this->chosen;
        $entry = $chosen ? Booklet::entries()->find($chosen->entry_id) : null;

        return view('livewire.admin.comments', [
            'statuses' => self::STATUSES,
            'counts' => $counts,
            'entry' => $entry,
            'titles' => $this->pile->pluck('entry_id')->unique()
                ->mapWithKeys(fn ($id) => [$id => Booklet::entries()->find($id)?->title() ?? __('a removed entry')]),
        ]);
    }
}
