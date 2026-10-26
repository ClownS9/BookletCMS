<?php

namespace App\Notifications;

use App\Booklet\Site;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Was this you?": sent when something about signing in changes (a new
 * password, a new email address, two-step sign-in switched off, a new passkey), so a
 * change someone else made doesn't go unnoticed.
 */
class SecurityNotice extends Notification
{
    public const KINDS = [
        'password' => ['Your password was changed', 'The password for your account was just changed.'],
        'password_reset' => ['Your password was reset', 'Your password was just reset using a link sent to this address, and every device was signed out.'],
        'email' => ['Your sign-in email was changed', 'The email address you sign in with was just changed to %s. This message goes to the old address, in case that wasn’t you.'],
        'two_factor_off' => ['Two-step sign-in was switched off', 'Two-step sign-in was just switched off for your account. Your password alone now opens it.'],
        'passkey' => ['A passkey was added', 'A passkey called “%s” was just added to your account. It opens the book on its own, without your password. If you don’t recognise it, remove it in Your account › Signing in.'],
    ];

    public function __construct(public string $kind, public string $name = '', public string $detail = '') {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        [$subject, $line] = self::KINDS[$this->kind] ?? self::KINDS['password'];
        $where = request()->ip() ? ' (from '.request()->ip().', '.now()->format('j F Y, g:i a').')' : '';

        return (new MailMessage)
            ->subject($subject.' · '.$site->name())
            ->greeting($this->name !== '' ? 'Hello '.$this->name.',' : 'Hello,')
            ->line(sprintf($line, $this->detail).$where)
            ->line('If this was you, there’s nothing to do.')
            ->line('If it wasn’t, reset your password straight away from the sign-in page (“Forgot password?”), and tell whoever looks after the site.')
            ->action('Go to sign in', route('login'))
            ->salutation('— Stacy, from '.$site->name());
    }

    /** Send without letting a mail problem stop what the person was doing. */
    public static function send(object $to, string $kind, string $name = '', string $detail = ''): void
    {
        rescue(fn () => $to->notify(new self($kind, $name, $detail)), null, true);
    }
}
