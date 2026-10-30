<?php

namespace App\Notifications;

use App\Booklet\Site;
use App\Support\Readers;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "You're in": the book let a waiting reader in. */
class ReaderLetIn extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        $mail = (new MailMessage)
            ->subject(__('You’re in at :site', ['site' => $site->name()]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->firstName()]))
            ->line(__('Your account at :site is ready. Sign in to read everything that’s open to members.', ['site' => $site->name()]))
            ->action(__('Sign in'), $site->absolute(route('readers.sign-in')));
        if (trim((string) Readers::get('welcome')) !== '') {
            $mail->line((string) Readers::get('welcome'));
        }

        return $mail->salutation('— '.$site->name());
    }
}
