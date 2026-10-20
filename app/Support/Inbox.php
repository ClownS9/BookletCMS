<?php

namespace App\Support;

use App\Booklet\Data\Form;
use App\Booklet\Data\Submission;
use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Notifications\NewMessage;
use App\Notifications\MessageReply;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Letters to the book: what readers send from the contact form on the
 * Contact page. They're kept with the form's submissions, with a few notes
 * of Booklet's own, in fields starting with "_":
 *
 *   _at       when it arrived
 *   _status   new, read, archived or spam
 *   _page     the page it was sent from
 *   _replies  [{by, at, body}] what was written back from here
 */
class Inbox
{
    public const FORM = 'contact';

    public const STATUSES = ['new', 'read', 'archived', 'spam'];

    /** Spam is forgotten after this many days. */
    public const SPAM_DAYS = 30;

    public const MESSAGE_MAX = 5000;

    public static function form(): ?Form
    {
        return rescue(fn () => Booklet::forms()->find(self::FORM), null, false);
    }

    /** The contact form, made on first use if this book never had one. */
    public static function ensureForm(): Form
    {
        if ($form = self::form()) {
            return $form;
        }

        Booklet::forms()->make(['handle' => self::FORM, 'title' => 'Contact'], ['honeypot' => 'website'])->save();
        if (! Booklet::blueprints()->findIn('forms', self::FORM)) {
            Booklet::blueprints()->make(['namespace' => 'forms', 'handle' => self::FORM], [
                'title' => 'Contact',
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'name', 'field' => ['type' => 'text', 'validate' => ['required']]],
                    ['handle' => 'email', 'field' => ['type' => 'text', 'input_type' => 'email', 'validate' => ['required', 'email']]],
                    ['handle' => 'message', 'field' => ['type' => 'textarea', 'validate' => ['required']]],
                ]]]]],
            ])->save();
        }

        return self::form();
    }

    /** Is the form showing on the Contact page? */
    public static function open(): bool
    {
        return (bool) (self::form()?->get('open', true) ?? false);
    }

    public static function setOpen(bool $open): void
    {
        self::ensureForm()->set('open', $open)->save();
    }

    /**
     * The form's fields, simplified: [handle => [label, type, required, input]].
     * Text and long text only (anything else is left off the public form).
     *
     * @return array<string, array{label: string, type: string, required: bool, input: string}>
     */
    public static function fields(): array
    {
        $blueprint = rescue(fn () => Booklet::blueprints()->findIn('forms', self::FORM), null, false);
        $fields = [];
        foreach ($blueprint?->fields() ?? [] as $f) {
            $handle = (string) ($f['handle'] ?? '');
            $config = (array) ($f['field'] ?? []);
            if (! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $handle) || str_starts_with($handle, '_') || ! in_array($config['type'] ?? 'text', ['text', 'textarea'], true)) {
                continue;
            }
            $validate = (array) ($config['validate'] ?? []);
            $fields[$handle] = [
                'label' => (string) ($config['display'] ?? Str::headline($handle)),
                'type' => $config['type'] ?? 'text',
                'required' => in_array('required', $validate, true),
                'input' => $handle === 'email' || ($config['input_type'] ?? '') === 'email' ? 'email' : 'text',
            ];
        }

        return $fields ?: [
            'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'input' => 'text'],
            'email' => ['label' => 'Email', 'type' => 'text', 'required' => true, 'input' => 'email'],
            'message' => ['label' => 'Message', 'type' => 'textarea', 'required' => true, 'input' => 'text'],
        ];
    }

    // Arriving -------------------------------------------------------------------

    /** Keep a message from the site, and tell the team (unless it looks like spam). */
    public static function receive(array $fields, string $page = '', bool $spam = false): Submission
    {
        self::ensureForm();

        /** @var Submission $message */
        $message = Booklet::submissions()->make(['form' => self::FORM], $fields + [
            '_at' => now()->toIso8601String(),
            '_status' => $spam ? 'spam' : 'new',
            '_page' => Str::limit($page, 300, ''),
        ])->save();

        self::changed();
        if (! $spam) {
            self::alert($message);
            app(\App\Inserts\InsertManager::class)->fire('message.received', $message);
        }

        return $message;
    }

    /** An email to admins and editors who want one; Reply goes straight to the sender. */
    protected static function alert(Submission $message): void
    {
        $people = User::query()->whereIn('role', \App\Models\Role::keysAtLevel(['admin', 'editor']))->get()
            ->filter(fn (User $u) => $u->email && (bool) (((array) $u->preferences)['notify_messages'] ?? true));

        foreach ($people as $person) {
            rescue(fn () => $person->notify(new NewMessage($message)), null, true);
        }
    }

    // Reading ----------------------------------------------------------------------

    /** @return Collection<int, Submission> newest first */
    public static function all(): Collection
    {
        return rescue(fn () => Booklet::submissions()->whereForm(self::FORM), collect(), false)
            ->sortByDesc(fn (Submission $s) => (string) $s->get('_at'))
            ->values();
    }

    /** How many haven't been opened (for the badge on the Inbox flag; kept for a minute). */
    public static function newCount(): int
    {
        return (int) rescue(fn () => Cache::remember('booklet.inbox.new', 60, fn () => self::counts()['new']), 0, false);
    }

    public static function changed(): void
    {
        Cache::forget('booklet.inbox.new');
    }

    /** @return array{new: int, inbox: int, archived: int, spam: int} */
    public static function counts(?Collection $all = null): array
    {
        $by = ($all ?? self::all())->countBy(fn (Submission $s) => self::status($s));

        return [
            'new' => $by['new'] ?? 0,
            'inbox' => ($by['new'] ?? 0) + ($by['read'] ?? 0),
            'archived' => $by['archived'] ?? 0,
            'spam' => $by['spam'] ?? 0,
        ];
    }

    public static function find(string $id): ?Submission
    {
        $s = rescue(fn () => Booklet::submissions()->find($id), null, false);

        return $s && $s->form() === self::FORM ? $s : null;
    }

    public static function status(Submission $s): string
    {
        return in_array($s->get('_status'), self::STATUSES, true) ? $s->get('_status') : 'new';
    }

    public static function at(Submission $s): ?Carbon
    {
        return $s->get('_at') ? rescue(fn () => Carbon::parse($s->get('_at')), null, false) : null;
    }

    /** Who sent it: [name, email]. */
    public static function sender(Submission $s): array
    {
        $email = null;
        foreach (self::fields() as $handle => $f) {
            if ($f['input'] === 'email' && filter_var($s->get($handle), FILTER_VALIDATE_EMAIL)) {
                $email = (string) $s->get($handle);
                break;
            }
        }

        return [trim((string) ($s->get('name') ?: $s->get('full_name') ?: '')) ?: ($email ? Str::before($email, '@') : 'Someone'), $email];
    }

    /** The message itself: the long text field, or everything that isn't the name or email. */
    public static function body(Submission $s): string
    {
        foreach (self::fields() as $handle => $f) {
            if ($f['type'] === 'textarea' && trim((string) $s->get($handle)) !== '') {
                return (string) $s->get($handle);
            }
        }

        return collect($s->data())->reject(fn ($v, $k) => str_starts_with($k, '_') || in_array($k, ['name', 'email'], true) || ! is_scalar($v))->implode("\n");
    }

    /**
     * Anything else they filled in (a phone number, a subject…), labelled.
     *
     * @return array<string, string>
     */
    public static function extras(Submission $s): array
    {
        $extras = [];
        foreach (self::fields() as $handle => $f) {
            $value = trim((string) $s->get($handle));
            if ($value !== '' && $f['type'] !== 'textarea' && $f['input'] !== 'email' && $handle !== 'name') {
                $extras[$f['label']] = $value;
            }
        }

        return $extras;
    }

    // Changing -----------------------------------------------------------------------

    public static function setStatus(Submission $s, string $status): void
    {
        if (in_array($status, self::STATUSES, true) && self::status($s) !== $status) {
            $s->set('_status', $status)->save();
            self::changed();
        }
    }

    /** Opening a new one marks it read. */
    public static function markRead(Submission $s): void
    {
        if (self::status($s) === 'new') {
            self::setStatus($s, 'read');
        }
    }

    /**
     * Write back by email (through the book's email settings). Their reply
     * goes to the person who wrote it, not to the book.
     */
    public static function reply(Submission $s, User $by, string $text): void
    {
        [$name, $email] = self::sender($s);
        if (! $email) {
            throw new \RuntimeException('There’s no email address to write back to.');
        }

        Notification::route('mail', [$email => $name])->notify(new MessageReply($s, $by, $text));

        $replies = (array) $s->get('_replies', []);
        $replies[] = ['by' => $by->id, 'at' => now()->toIso8601String(), 'body' => $text];
        $s->set('_replies', $replies);
        if (in_array(self::status($s), ['new', 'spam'], true)) {
            $s->set('_status', 'read');
        }
        $s->save();
        self::changed();
    }

    public static function delete(Submission $s): void
    {
        $s->delete();
        self::changed();
    }

    /** Spam is forgotten after a month. Returns how many went. */
    public static function tidy(): int
    {
        $old = self::all()->filter(fn (Submission $s) => self::status($s) === 'spam' && self::at($s)?->lt(now()->subDays(self::SPAM_DAYS)));
        $old->each(fn (Submission $s) => $s->delete());
        self::changed();

        return $old->count();
    }
}
