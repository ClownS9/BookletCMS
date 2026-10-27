<?php

namespace App\Livewire\Site;

use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Models\Comment;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Notes in the margin": the approved comments under a post, and the form
 * to leave one. New notes wait for approval unless Settings say otherwise.
 */
class Comments extends Component
{
    use \App\Livewire\Concerns\Guarded;

    #[Locked]
    public string $entryId;

    public string $name = '';

    public string $email = '';

    public string $body = '';

    /** Email them when someone replies (only with an email, and only if they tick it). */
    public bool $notify = false;

    /** The note they're replying to (a conversation is one note and its replies). */
    public ?int $replyTo = null;

    /** Honeypot: people never see it, bots fill it in. */
    public string $website = '';

    public ?string $posted = null;

    /** Whether what they just posted was a reply (for the thank-you). */
    public bool $postedReply = false;

    public function mount(string $entryId): void
    {
        $this->entryId = $entryId;
    }

    /** Each change speaks the post's language (a translation's notes in its own). */
    public function hydrate(): void
    {
        if ($entry = Booklet::entries()->find($this->entryId)) {
            app()->setLocale(app(Site::class)->languageOf($entry));
        }
    }

    /** A note people can reply to: approved, under this entry, and the first in its conversation. */
    protected function replyable(?int $id): ?Comment
    {
        return $id ? Comment::query()->whereKey($id)->where('entry_id', $this->entryId)->whereNull('parent_id')->where('status', 'approved')->first() : null;
    }

    public function startReply(int $id): void
    {
        $this->replyTo = $this->replyable($id)?->id;
        $this->posted = null;
        $this->resetErrorBag();
    }

    public function cancelReply(): void
    {
        $this->replyTo = null;
    }

    public function post(Site $site): void
    {
        // Only on posts that are out in the world (not drafts someone guessed the id of).
        abort_unless($site->commentsOpen() && Booklet::entries()->find($this->entryId)?->status() === 'published', 403);

        if ($this->website !== '') {
            // A bot. Pretend all went well and keep nothing.
            $this->reset('name', 'email', 'body', 'website');
            $this->posted = 'waiting';

            return;
        }

        // Invisible characters and stray spaces go before anything is checked.
        $clean = fn (string $v) => trim(preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $v) ?? '');
        $this->name = $clean($this->name);
        $this->email = mb_strtolower($clean($this->email));
        $this->body = $clean($this->body);

        $this->validate([
            'name' => ['required', 'string', 'max:80', 'not_regex:/https?:\/\/|www\.|[<>]/i'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'body' => ['required', 'string', 'min:2', 'max:5000'],
        ], [
            'name.not_regex' => __('Just your name, please (no links).'),
        ], ['body' => __('note')]);

        // A guarding insert (a CAPTCHA, a block list) has its say first.
        if (! $this->guarded('comment', ['name' => $this->name, 'email' => $this->email, 'body' => $this->body])) {
            return;
        }

        $key = 'comment:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('body', __('That’s a lot of notes at once. Please wait a few minutes.'));

            return;
        }
        RateLimiter::hit($key, 600);

        // Notes full of links wait for a person to look, whatever the setting.
        $links = preg_match_all('#https?://|www\.#i', $this->body);
        $status = $site->holdComments() || $links > 2 ? 'waiting' : 'approved';

        // A reply joins its conversation; if that note has gone, it becomes a note of its own.
        $parent = $this->replyable($this->replyTo);

        $comment = Comment::create([
            'entry_id' => $this->entryId,
            'parent_id' => $parent?->id,
            'author_name' => $this->name,
            'author_email' => $this->email ?: null,
            'body' => $this->body,
            'status' => $status,
            'notify' => $this->notify && $this->email !== '',
        ]);

        // An email to the entry's author (if they want one).
        \App\Support\CommentAlerts::send($comment);
        app(\App\Inserts\InsertManager::class)->fire('comment.received', $comment);

        $this->reset('body', 'replyTo');
        $this->posted = $status;
        $this->postedReply = (bool) $parent;
    }

    public function render()
    {
        $comments = Comment::query()
            ->where('entry_id', $this->entryId)
            ->whereNull('parent_id')
            ->where('status', 'approved')
            ->with(['replies' => fn ($q) => $q->where('status', 'approved')->orderBy('created_at')])
            ->orderBy('created_at')
            ->get();

        return view('livewire.site.comments', [
            'comments' => $comments,
            'count' => $comments->count() + $comments->sum(fn ($c) => $c->replies->count()),
            'replyingTo' => $this->replyTo ? $comments->firstWhere('id', $this->replyTo) : null,
        ]);
    }
}
