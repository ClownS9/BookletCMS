<?php

namespace App\Notifications;

use App\Booklet\Data\Submission;
use App\Booklet\Site;
use App\Support\Inbox;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new message from the contact form, to admins and editors who want one.
 * Replying to this email writes straight back to the sender.
 */
class NewMessage extends Notification
{
    public function __construct(public Submission $message) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        [$name, $email] = Inbox::sender($this->message);
        $body = trim(Inbox::body($this->message));

        $mail = (new MailMessage)
            ->subject($this->message->form() === Inbox::FORM
                ? Str::limit($name, 40).' wrote: '.Str::limit(preg_replace('/\s+/', ' ', $body), 60)
                : Str::limit(Inbox::formName($this->message), 50).': '.Str::limit($name, 40).' filled it in')
            ->greeting($this->message->form() === Inbox::FORM ? 'A letter to '.$site->name() : 'An answer to “'.Str::limit(Inbox::formName($this->message), 80).'”')
            ->line(Str::limit($name, 80).($email ? ' ('.$email.')' : '').($this->message->form() === Inbox::FORM ? ' sent a message from the contact form:' : ' filled in the form:'));

        foreach (array_filter(preg_split("/\n\s*\n/", Str::limit($body, 3000))) as $paragraph) {
            $mail->line('“'.trim($paragraph).'”');
        }
        foreach (Inbox::extras($this->message) as $label => $value) {
            $mail->line(Inbox::labelled((string) $label, Str::limit($value, 300)));
        }
        if ($email) {
            $mail->replyTo($email, Str::limit($name, 80, ''));
        }

        return $mail
            ->action('Open the inbox', route('admin.inbox', ['message' => $this->message->id()]))
            ->line($email ? 'Reply to this email to write back to them, or write back from the inbox.' : 'They didn’t leave an email address.')
            ->salutation('— Stacy, from '.$site->name())
            ->line('You get these because “Messages and form answers” is on in Your account.');
    }
}
