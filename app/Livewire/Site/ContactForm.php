<?php

namespace App\Livewire\Site;

use App\Support\Inbox;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The contact form on the Contact page. Messages land in the admin's
 * Inbox, and admins and editors get an email.
 */
class ContactForm extends Component
{
    use \App\Livewire\Concerns\Guarded;

    /** Field values, by the form's field handles. */
    public array $values = [];

    /** Honeypot: people never see it, bots fill it in. */
    public string $website = '';

    #[Locked]
    public string $page = '';

    /** When the form was shown: a person takes more than a couple of seconds. */
    #[Locked]
    public int $shownAt = 0;

    public bool $sent = false;

    public function mount(string $page = ''): void
    {
        $this->page = $page;
        $this->shownAt = now()->timestamp;
        foreach (Inbox::fields() as $handle => $f) {
            $this->values[$handle] = '';
        }
    }

    public function send(): void
    {
        abort_unless(Inbox::open(), 403);
        $fields = Inbox::fields();

        if ($this->website !== '') {
            // A bot. Pretend all went well and keep nothing.
            $this->finish();

            return;
        }

        // Invisible characters and stray spaces go before anything is checked.
        $clean = fn ($v) => trim(preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $v) ?? '');
        $values = [];
        foreach ($fields as $handle => $f) {
            $values[$handle] = $clean($this->values[$handle] ?? '');
            if ($f['input'] === 'email') {
                $values[$handle] = mb_strtolower($values[$handle]);
            }
        }
        $this->values = $values;

        $rules = $names = [];
        foreach ($fields as $handle => $f) {
            $rules['values.'.$handle] = array_values(array_filter([
                $f['required'] ? 'required' : 'nullable',
                'string',
                $f['input'] === 'email' ? 'email:rfc' : null,
                $f['type'] === 'textarea' ? 'max:'.Inbox::MESSAGE_MAX : 'max:190',
                $f['type'] === 'textarea' ? 'min:2' : null,
                $handle === 'name' ? 'not_regex:/https?:\/\/|www\.|[<>]/i' : null,
            ]));
            $names['values.'.$handle] = mb_strtolower($f['label']);
        }
        $this->validate($rules, ['values.name.not_regex' => __('Just your name, please (no links).')], $names);

        if (! $this->guarded('contact', $values)) {
            return;
        }

        $key = 'contact:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('values.'.array_key_last($fields), __('That’s a few messages already. Please wait a little while and try again.'));

            return;
        }
        RateLimiter::hit($key, 900);

        // Sent too quickly to have been typed, or full of links: kept, but in Spam.
        $text = implode(' ', $values);
        $spam = now()->timestamp - $this->shownAt < 3 || preg_match_all('#https?://|www\.#i', $text) > 2;

        Inbox::receive($values, $this->page, $spam);
        $this->finish();
    }

    protected function finish(): void
    {
        foreach (array_keys($this->values) as $handle) {
            $this->values[$handle] = '';
        }
        $this->website = '';
        $this->sent = true;
    }

    public function again(): void
    {
        $this->sent = false;
        $this->shownAt = now()->timestamp;
    }

    public function render()
    {
        return view('livewire.site.contact-form', ['fields' => Inbox::fields()]);
    }
}
