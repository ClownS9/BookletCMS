<?php

namespace App\Notifications;

use App\Booklet\Site;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A link to make a new password, for a reader. */
class ReaderPasswordReset extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);

        return (new MailMessage)
            ->subject(__('A new password for :site', ['site' => $site->name()]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->firstName()]))
            ->line(__('Someone (hopefully you) asked to make a new password for your account at :site.', ['site' => $site->name()]))
            ->action(__('Make a new password'), $site->absolute(route('readers.reset', ['token' => $this->token, 'email' => $notifiable->email])))
            ->line(__('The link works for an hour. If it wasn’t you, ignore this email: your password hasn’t changed.'))
            ->salutation('— '.$site->name());
    }
}
