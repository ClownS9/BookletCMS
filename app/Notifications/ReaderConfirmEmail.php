<?php

namespace App\Notifications;

use App\Booklet\Site;
use App\Support\Readers;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Is this your address?": the first email a new reader gets. */
class ReaderConfirmEmail extends Notification
{
    public function __construct(public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        $mail = (new MailMessage)
            ->subject(__('Confirm your account at :site', ['site' => $site->name()]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->firstName()]))
            ->line(__('Thanks for joining :site. Press the button to confirm this is your email address.', ['site' => $site->name()]))
            ->action(__('Confirm my address'), $this->url);
        if (Readers::joining() === 'approval' && ! $notifiable->approved_at) {
            $mail->line(__('Then someone at :site lets you in, and we’ll email you when they have.', ['site' => $site->name()]));
        }
        if (trim((string) Readers::get('welcome')) !== '') {
            $mail->line((string) Readers::get('welcome'));
        }

        return $mail->line(__('If you didn’t make an account, ignore this email and nothing more happens.'))
            ->salutation('— '.$site->name());
    }
}
