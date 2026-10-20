<?php

namespace App\Notifications;

use App\Booklet\Site;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Stacy's Monday letter: how the site did last week and what's next.
 * Sent only to people with "Weekly summary" switched on.
 */
class WeeklyLetter extends Notification
{
    /** @param  array  $week  from App\Stacy\WeeklyLetter::for() */
    public function __construct(public array $week) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $w = $this->week;
        $site = app(Site::class);

        $mail = (new MailMessage)
            ->subject($w['subject'])
            ->greeting($w['hello']);

        foreach ($w['paragraphs'] as $paragraph) {
            $mail->line($paragraph);
        }

        return $mail
            ->action('Open the book', route('admin.contents'))
            ->line($w['signoff'])
            ->salutation('— Stacy, from '.$site->name())
            ->line('You get this because “Weekly summary” is on in Your account.');
    }
}
