<?php

namespace App\Notifications;

use App\Booklet\Data\Entry;
use App\Booklet\Site;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Review before publishing, by email: a draft is ready for review (to
 * editors and admins), or it was published, scheduled or sent back (to the
 * writer). Sent only to people with "Reviews" switched on.
 */
class ReviewNews extends Notification
{
    /** @param  string  $kind  asked, returned, published, scheduled */
    public function __construct(public string $kind, public Entry $entry, public User $by, public string $note = '') {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(Site::class);
        $title = Str::limit($this->entry->title() ?: 'Untitled', 70);
        $who = Str::limit($this->by->name, 40);
        $edit = route('admin.entries.edit', [$this->entry->collectionHandle(), $this->entry->id()]);

        $mail = match ($this->kind) {
            'asked' => (new MailMessage)
                ->subject($who.' asked you to review “'.$title.'”')
                ->greeting('Ready for a read')
                ->line($who.' finished a draft and would like it reviewed before it goes out: “'.$title.'”.')
                ->when($this->note !== '', fn ($m) => $m->line('Their note: “'.$this->note.'”'))
                ->line('Publish it when it’s ready, or send it back with a note.')
                ->action('Read the draft', $edit),
            'returned' => (new MailMessage)
                ->subject('“'.$title.'” came back with a note')
                ->greeting('A few changes first')
                ->line($who.' read “'.$title.'” and sent it back:')
                ->line('“'.$this->note.'”')
                ->line('Make the changes, then ask for a review again.')
                ->action('Open the draft', $edit),
            default => (new MailMessage)
                ->subject('“'.$title.'” is '.($this->kind === 'scheduled' ? 'scheduled' : 'live'))
                ->greeting($this->kind === 'scheduled' ? 'It’s on the calendar' : 'It’s out in the world')
                ->line($who.($this->kind === 'scheduled'
                    ? ' reviewed “'.$title.'” and scheduled it for '.($this->entry->date()?->format('l j F \a\t g:i a') ?? 'later').'.'
                    : ' reviewed “'.$title.'” and published it.'))
                ->when($this->kind === 'published', fn ($m) => $m->action('Read it on the site', $site->absolute(url($this->entry->uri() ?? '/'))), fn ($m) => $m->action('Open it', $edit)),
        };

        return $mail
            ->salutation('— Stacy, from '.$site->name())
            ->line('You get these because “Reviews” is on in Your account.');
    }
}
