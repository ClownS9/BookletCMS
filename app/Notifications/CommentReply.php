<?php

namespace App\Notifications;

use App\Booklet\Data\Entry;
use App\Booklet\Site;
use App\Models\Comment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Someone replied in a conversation a reader asked to hear about.
 */
class CommentReply extends Notification
{
    public function __construct(public Comment $reply, public Entry $entry, public bool $toTheirNote, public string $stop) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        $title = $this->entry->title() ?: 'a post';
        $who = $this->reply->author_name ?: 'Someone';
        $link = $site->absolute($site->url($this->entry)).'#c-'.$this->reply->id;

        $mail = (new MailMessage)
            ->subject($this->toTheirNote ? $who.' replied to your note on “'.$title.'”' : 'A new reply in the conversation on “'.$title.'”')
            ->greeting($this->toTheirNote ? 'A reply to your note' : 'A new reply')
            ->line($who.' wrote, under “'.$title.'”:')
            ->line('“'.Str::limit(preg_replace('/\s+/', ' ', trim($this->reply->body)), 600).'”')
            ->action('Read it and reply', $link)
            ->line('You’re getting this because you asked to hear about replies. [Stop emails about this conversation]('.$this->stop.')')
            ->salutation($site->name());

        // Mail apps show their own "Unsubscribe" with these.
        return $mail->withSymfonyMessage(function (Email $message) {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->stop.'>');
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
