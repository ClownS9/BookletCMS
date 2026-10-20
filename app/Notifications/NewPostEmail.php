<?php

namespace App\Notifications;

use App\Booklet\Data\Entry;
use App\Booklet\Site;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/** A new post, for someone who asked for them. */
class NewPostEmail extends Notification
{
    public function __construct(public Entry $entry) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        $url = $site->absolute($site->url($this->entry));
        $leave = $site->absolute(route('site.unsubscribe', $notifiable->token));
        $standfirst = trim((string) $this->entry->get('excerpt')) ?: $site->excerpt($this->entry, 220);
        $author = $site->author($this->entry);

        $mail = (new MailMessage)
            ->subject($this->entry->title() ?: 'A new post from '.$site->name())
            ->greeting($this->entry->title() ?: 'A new post')
            ->line(Str::limit($standfirst, 300))
            ->line(($author ? 'By '.($author->byline ?: $author->name).' · ' : '').$site->readingTime($this->entry).' min read')
            ->action('Read it', $url)
            ->salutation('— '.$site->name())
            ->line('You get these because you asked for new posts from '.$site->name().'. [Unsubscribe]('.$leave.') any time, in one click.');

        // Mail apps show their own "Unsubscribe" button with these (RFC 2369 and 8058).
        return $mail->withSymfonyMessage(function (Email $message) use ($leave) {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$leave.'>');
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
