<?php

namespace App\Notifications;

use App\Booklet\Data\Entry;
use App\Booklet\Site;
use App\Models\Comment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "A note in the margin": someone left a comment on your entry.
 * Sent only to people with "New comments on my entries" switched on.
 */
class NewComment extends Notification
{
    public function __construct(public Comment $comment, public Entry $entry) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        $title = $this->entry->title() ?: 'Untitled';
        $waiting = $this->comment->status === 'waiting';

        return (new MailMessage)
            ->subject(Str::limit($this->comment->author_name, 40).' left a note on “'.Str::limit($title, 60).'”')
            ->greeting('A new note in the margin')
            ->line(Str::limit($this->comment->author_name, 80).' wrote on “'.$title.'”:')
            ->line('“'.Str::limit(trim($this->comment->body), 400).'”')
            ->line($waiting ? 'It’s waiting for approval before it shows on the site.' : 'It’s already showing on the site.')
            ->action($waiting ? 'Read and approve' : 'Read and reply', route('admin.comments'))
            ->salutation('— Stacy, from '.$site->name())
            ->line('You get these because “New comments on my entries” is on in Your account.');
    }
}
