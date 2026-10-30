<?php

namespace App\Notifications;

use App\Booklet\Site;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone tried to make an account with an address that already has one.
 * The site says the same as for a new account (so nobody learns who has
 * one); the owner of the address hears about it here.
 */
class ReaderAlreadyHere extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);

        return (new MailMessage)
            ->subject(__('You already have an account at :site', ['site' => $site->name()]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->firstName()]))
            ->line(__('Someone (hopefully you) tried to make an account at :site with this address, and it already has one.', ['site' => $site->name()]))
            ->action(__('Sign in'), $site->absolute(route('readers.sign-in')))
            ->line(__('Forgotten the password? There’s a link to make a new one on the sign-in page. If it wasn’t you, nothing has changed.'))
            ->salutation('— '.$site->name());
    }
}
