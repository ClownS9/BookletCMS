<?php

namespace App\Notifications;

use App\Booklet\Site;
use App\Support\ReaderAccounts;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An invitation from the book to make an account on the site. */
class ReaderInvitation extends Notification
{
    public function __construct(public string $url, public ?string $from = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);

        return (new MailMessage)
            ->subject(__('An invitation to :site', ['site' => $site->name()]))
            ->greeting(__('Hello,'))
            ->line($this->from
                ? __(':name invited you to make an account at :site.', ['name' => $this->from, 'site' => $site->name()])
                : __('You’re invited to make an account at :site.', ['site' => $site->name()]))
            ->action(__('Make my account'), $this->url)
            ->line(__('The link works for :days days. If you weren’t expecting this, ignore it.', ['days' => ReaderAccounts::INVITE_DAYS]))
            ->salutation('— '.$site->name());
    }
}
