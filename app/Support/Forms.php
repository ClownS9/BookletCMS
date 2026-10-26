<?php

namespace App\Support;

use App\Booklet\Data\Form;
use App\Booklet\Data\Submission;
use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Notifications\NewMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Forms of your own (Comments › Forms): a sign-up, an RSVP, an order. Each
 * is one of the book's forms, marked "made", with its questions kept on it:
 *
 *   fields  [{key, label, type, required, options[], help}]
 *   thanks  what readers see once they've sent it
 *   button  the button's words
 *   open    taking answers
 *   notify  email the team about each answer
 *
 * Answers are the form's submissions, kept like the contact form's (with
 * Booklet's own notes in "_" fields), so they arrive in the Inbox. A form
 * goes on a page as the page builder's "A form" section.
 */
class Forms
{
    /** Kinds of question: type => what it's called in the book. */
    public const KINDS = [
        'text' => 'A line',
        'long' => 'A few lines',
        'email' => 'Email address',
        'phone' => 'Phone number',
        'number' => 'A number',
        'date' => 'A date',
        'choice' => 'One from a list',
        'choices' => 'Any from a list',
        'tick' => 'A tick box',
    ];

    public const MAX_FORMS = 50;

    public const MAX_FIELDS = 30;

    public const MAX_OPTIONS = 30;

    public const LONG_MAX = 5000;

    // The forms ----------------------------------------------------------------------

    /** @return Collection<int, Form> by name */
    public static function all(): Collection
    {
        return rescue(fn () => Booklet::forms()->all(), collect(), false)
            ->filter(fn (Form $f) => (bool) $f->get('made'))
            ->sortBy(fn (Form $f) => Str::lower($f->title()))
            ->values();
    }

    public static function find(?string $handle): ?Form
    {
        if (! is_string($handle) || $handle === '' || $handle === Inbox::FORM) {
            return null;
        }
        $form = rescue(fn () => Booklet::forms()->find($handle), null, false);

        return $form && $form->get('made') ? $form : null;
    }

    /** Forms to choose from (the page builder's "A form" section): handle => name. */
    public static function choices(): array
    {
        return ['' => 'Choose a form'] + self::all()->mapWithKeys(fn (Form $f) => [$f->handle() => $f->title()])->all();
    }

    /** A new form, with the usual three questions to start from. */
    public static function create(string $name): Form
    {
        $name = Str::limit(trim(preg_replace('/\s+/', ' ', $name) ?? ''), 80, '');
        if ($name === '') {
            throw new \InvalidArgumentException('Give it a name.');
        }
        if (self::all()->count() >= self::MAX_FORMS) {
            throw new \InvalidArgumentException('There are '.self::MAX_FORMS.' forms already. Delete one you don’t use first.');
        }
        $base = Str::limit(Str::slug($name), 40, '') ?: 'form';
        $handle = $base;
        for ($n = 2; $handle === Inbox::FORM || rescue(fn () => Booklet::forms()->find($handle), null, false); $n++) {
            $handle = $base.'-'.$n;
        }

        Booklet::forms()->make(['handle' => $handle, 'title' => $name], [
            'made' => true,
            'fields' => [
                ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'options' => [], 'help' => ''],
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'options' => [], 'help' => ''],
                ['key' => 'message', 'label' => 'Anything else?', 'type' => 'long', 'required' => false, 'options' => [], 'help' => ''],
            ],
            'thanks' => 'Thank you. We’ve got it, and we’ll be in touch if we need to be.',
            'button' => 'Send',
            'open' => true,
            'notify' => true,
        ])->save();

        return self::find($handle) ?? throw new \RuntimeException('The form wasn’t saved.');
    }

    /**
     * A form's questions, tidied: every one has a key, a known kind, and (for
     * lists) its choices.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options: list<string>, help: string}>
     */
    public static function fields(Form $form): array
    {
        $out = [];
        $seen = [];
        foreach (array_slice(array_values(array_filter((array) $form->get('fields', []), 'is_array')), 0, self::MAX_FIELDS) as $f) {
            $label = Str::limit(trim((string) ($f['label'] ?? '')), 160, '');
            $key = (string) ($f['key'] ?? '');
            if ($label === '' || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $type = array_key_exists($f['type'] ?? '', self::KINDS) ? $f['type'] : 'text';
            $options = in_array($type, ['choice', 'choices'], true) ? self::options($f['options'] ?? []) : [];
            $out[] = [
                'key' => $key, 'label' => $label, 'type' => $type, 'required' => (bool) ($f['required'] ?? false),
                'options' => $options, 'help' => Str::limit(trim((string) ($f['help'] ?? '')), 200, ''),
            ];
        }

        return $out;
    }

    /** @return list<string> a list's choices: one each, no repeats, no blanks */
    public static function options(mixed $options): array
    {
        $lines = is_array($options) ? $options : preg_split('/\r?\n/', (string) $options);

        return array_slice(array_values(array_unique(array_filter(array_map(fn ($o) => Str::limit(trim((string) $o), 120, ''), (array) $lines), fn ($o) => $o !== ''))), 0, self::MAX_OPTIONS);
    }

    /**
     * Save a form's questions and words from the Forms page: a new question
     * gets a key from its words, and keeps it (answers already in stay matched).
     */
    public static function save(Form $form, string $name, array $fields, string $thanks, string $button, bool $open, bool $notify): void
    {
        $keys = [];
        $clean = [];
        foreach (array_slice(array_values(array_filter($fields, 'is_array')), 0, self::MAX_FIELDS) as $f) {
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '') {
                continue; // an empty question isn't a question
            }
            $key = (string) ($f['key'] ?? '');
            if (! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) || isset($keys[$key])) {
                $base = Str::limit(Str::snake(Str::slug(Str::ascii($label), ' ')), 32, '') ?: 'question';
                $base = preg_match('/^[a-z]/', $base) ? $base : 'q_'.$base;
                $key = $base;
                for ($n = 2; isset($keys[$key]); $n++) {
                    $key = $base.'_'.$n;
                }
            }
            $keys[$key] = true;
            $type = array_key_exists($f['type'] ?? '', self::KINDS) ? (string) $f['type'] : 'text';
            $clean[] = [
                'key' => $key,
                'label' => Str::limit($label, 160, ''),
                'type' => $type,
                'required' => (bool) ($f['required'] ?? false),
                'options' => in_array($type, ['choice', 'choices'], true) ? self::options($f['options'] ?? []) : [],
                'help' => Str::limit(trim((string) ($f['help'] ?? '')), 200, ''),
            ];
        }

        $form->setAttribute('title', Str::limit(trim($name), 80, '') ?: $form->title())
            ->set('fields', $clean)
            ->set('thanks', Str::limit(trim($thanks), 600, ''))
            ->set('button', Str::limit(trim($button), 40, '') ?: 'Send')
            ->set('open', $open)
            ->set('notify', $notify)
            ->save();
    }

    /** It goes, with every answer it had. */
    public static function delete(Form $form): void
    {
        rescue(fn () => Booklet::submissions()->whereForm($form->handle())->each(fn (Submission $s) => $s->delete()), null, false);
        $form->delete();
        Inbox::changed();
    }

    // Answering ------------------------------------------------------------------------

    /** Is it on its page for readers? */
    public static function open(Form $form): bool
    {
        return (bool) $form->get('open', true) && self::fields($form) !== [];
    }

    /**
     * What readers sent, checked: [rules, names] for the validator, by
     * "values.{key}".
     *
     * @return array{0: array<string, array>, 1: array<string, string>}
     */
    public static function rules(Form $form): array
    {
        $rules = $names = [];
        foreach (self::fields($form) as $f) {
            $at = 'values.'.$f['key'];
            $need = $f['required'] ? 'required' : 'nullable';
            $rules[$at] = match ($f['type']) {
                'long' => [$need, 'string', 'max:'.self::LONG_MAX],
                'email' => [$need, 'string', 'max:190', 'email:rfc'],
                'phone' => [$need, 'string', 'max:40', 'regex:/^[+0-9 ()\-.]{5,40}$/'],
                'number' => [$need, 'numeric', 'between:-1000000000,1000000000'],
                'date' => [$need, 'date_format:Y-m-d'],
                'choice' => [$need, 'string', Rule::in($f['options'])],
                'choices' => [$f['required'] ? 'required' : 'nullable', 'array', 'max:'.self::MAX_OPTIONS],
                'tick' => $f['required'] ? ['accepted'] : ['nullable', 'boolean'],
                default => [$need, 'string', 'max:300', 'not_regex:/[<>]/'],
            };
            if ($f['type'] === 'choices') {
                $rules[$at.'.*'] = ['string', Rule::in($f['options'])];
            }
            $names[$at] = Str::lower(Str::limit($f['label'], 60, '…'));
            $names[$at.'.*'] = Str::lower(Str::limit($f['label'], 60, '…'));
        }

        return [$rules, $names];
    }

    /**
     * Answers as they're kept: text tidied (invisible characters out), an
     * email in small letters, a list of choices as a list, a tick as "Yes".
     *
     * @return array<string, mixed>
     */
    public static function clean(Form $form, array $values): array
    {
        $tidy = fn ($v) => trim(preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $v) ?? '');
        $out = [];
        foreach (self::fields($form) as $f) {
            $v = $values[$f['key']] ?? null;
            $out[$f['key']] = match ($f['type']) {
                'choices' => array_values(array_intersect($f['options'], array_map($tidy, array_filter((array) $v, 'is_scalar')))),
                'tick' => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : '',
                'email' => mb_strtolower($tidy($v)),
                default => is_scalar($v) ? $tidy($v) : '',
            };
        }

        return $out;
    }

    /** Keep an answer, and tell the team (unless it looks like spam or they'd rather not hear). */
    public static function receive(Form $form, array $answers, string $page = '', bool $spam = false): Submission
    {
        /** @var Submission $answer */
        $answer = Booklet::submissions()->make(['form' => $form->handle()], array_filter($answers, fn ($v) => $v !== '' && $v !== []) + [
            '_at' => now()->toIso8601String(),
            '_status' => $spam ? 'spam' : 'new',
            '_page' => Str::limit($page, 300, ''),
        ])->save();

        Inbox::changed();
        if (! $spam && $form->get('notify', true)) {
            $people = User::query()->whereIn('role', \App\Models\Role::keysAtLevel(['admin', 'editor']))->get()
                ->filter(fn (User $u) => $u->email && (bool) (((array) $u->preferences)['notify_messages'] ?? true));
            foreach ($people as $person) {
                rescue(fn () => $person->notify(new NewMessage($answer)), null, true);
            }
        }
        if (! $spam) {
            app(\App\Inserts\InsertManager::class)->fire('message.received', $answer);
        }

        return $answer;
    }

    /** How many answers it has (spam left out). */
    public static function count(Form $form): int
    {
        return rescue(fn () => Booklet::submissions()->whereForm($form->handle())
            ->reject(fn (Submission $s) => Inbox::status($s) === 'spam')->count(), 0, false);
    }

    // Taking them away ---------------------------------------------------------------------

    /**
     * Every answer as a spreadsheet's rows (CSV): when, then each question,
     * oldest first. Spam is left out. Cells that a spreadsheet would read as
     * a formula are kept as plain words.
     */
    public static function csv(Form $form): string
    {
        $fields = self::fields($form);
        $rows = [['Sent', ...array_column($fields, 'label'), 'Status', 'From page']];
        $answers = rescue(fn () => Booklet::submissions()->whereForm($form->handle()), collect(), false)
            ->reject(fn (Submission $s) => Inbox::status($s) === 'spam')
            ->sortBy(fn (Submission $s) => (string) $s->get('_at'));
        foreach ($answers as $s) {
            $row = [Inbox::at($s)?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? ''];
            foreach ($fields as $f) {
                $v = $s->get($f['key']);
                $row[] = is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : (string) $v;
            }
            $row[] = ucfirst(Inbox::status($s));
            $row[] = (string) $s->get('_page');
            $rows[] = $row;
        }

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}"); // so spreadsheets read accents and the like right
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) ? "'".$cell : (string) $cell, $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
