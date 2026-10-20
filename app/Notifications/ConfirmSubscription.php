<?php

namespace App\Notifications;

use App\Booklet\Site;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Is this you?": the one email someone gets before they're on the list. */
class ConfirmSubscription extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);

        return (new MailMessage)
            ->subject('Confirm: new posts from '.$site->name())
            ->greeting($notifiable->name ? 'Hello '.$notifiable->name.',' : 'Hello,')
            ->line('Someone (hopefully you) asked to get new posts from '.$site->name().' by email.')
            ->action('Yes, send me new posts', $site->absolute(route('site.subscribe.confirm', $notifiable->token)))
            ->line('If it wasn’t you, ignore this email and nothing happens; you won’t hear from us again.')
            ->salutation('— '.$site->name());
    }
}
