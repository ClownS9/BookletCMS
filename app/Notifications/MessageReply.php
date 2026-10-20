<?php

namespace App\Notifications;

use App\Booklet\Data\Submission;
use App\Booklet\Site;
use App\Models\User;
use App\Support\Inbox;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Writing back to someone who used the contact form. Their answer goes to
 * the person who wrote it, not to the book.
 */
class MessageReply extends Notification
{
    public function __construct(public Submission $message, public User $by, public string $text) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        [$name] = Inbox::sender($this->message);
        $first = Str::before(trim($name), ' ');

        $mail = (new MailMessage)
            ->subject('Re: your message to '.$site->name())
            ->greeting('Hello'.($first !== '' && $first !== 'Someone' ? ' '.$first : '').',')
            ->replyTo($this->by->email, $this->by->name);

        foreach (array_filter(preg_split("/\n\s*\n/", trim($this->text))) as $paragraph) {
            $mail->line(trim($paragraph));
        }

        return $mail
            ->line('—')
            ->line('You wrote: “'.Str::limit(preg_replace('/\s+/', ' ', trim(Inbox::body($this->message))), 300).'”')
            ->salutation(($this->by->byline ?: $this->by->name).', '.$site->name());
    }
}
