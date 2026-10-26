<?php

namespace App\Livewire\Site;

use App\Support\Forms;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One of your own forms (Comments › Forms) on a page, from the page
 * builder's "A form" section. Answers land in the Inbox. The same care as
 * the contact form: a honeypot, a guard if an insert offers one (the
 * contact form's), a few sends at most, and quick or link-heavy ones go to Spam.
 */
class MadeForm extends Component
{
    use \App\Livewire\Concerns\Guarded;

    #[Locked]
    public string $handle = '';

    #[Locked]
    public string $page = '';

    /** Answers, by the questions' keys. */
    public array $values = [];

    /** Honeypot: people never see it, bots fill it in. */
    public string $website = '';

    #[Locked]
    public int $shownAt = 0;

    public bool $sent = false;

    public function mount(string $handle, string $page = ''): void
    {
        $this->handle = $handle;
        $this->page = $page;
        $this->shownAt = now()->timestamp;
        $this->blank();
    }

    protected function blank(): void
    {
        $form = Forms::find($this->handle);
        $this->values = [];
        foreach ($form ? Forms::fields($form) : [] as $f) {
            $this->values[$f['key']] = match ($f['type']) { 'choices' => [], 'tick' => false, default => '' };
        }
    }

    public function send(): void
    {
        $form = Forms::find($this->handle);
        abort_unless($form && Forms::open($form), 403);

        if ($this->website !== '') {
            $this->finish(); // a bot: all seems well, nothing is kept

            return;
        }

        [$rules, $names] = Forms::rules($form);
        $this->validate($rules, [
            'values.*.required' => 'Please fill this in.',
            'values.*.email' => 'That doesn’t look like an email address.',
            'values.*.numeric' => 'Just a number, please.',
            'values.*.max' => 'That’s a little long. Please shorten it.',
            'values.*.not_regex' => 'Just words, please (no < or >).',
            'values.*.*.in' => 'Choose from the choices.',
            'values.*.accepted' => 'Tick this to send the form.',
            'values.*.regex' => 'That doesn’t look like a phone number.',
            'values.*.date_format' => 'Choose a day.',
            'values.*.in' => 'Choose one of the choices.',
        ], $names);
        $answers = Forms::clean($form, $this->values);

        if (! $this->guarded('contact', $answers)) {
            return;
        }

        $key = 'forms:'.$form->handle().':'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('guard', 'That’s a few already. Please wait a little while and try again.');

            return;
        }
        RateLimiter::hit($key, 900);

        // Too quick to have been filled in by hand, or full of links: kept, but in Spam.
        $text = implode(' ', array_map(fn ($v) => is_array($v) ? implode(' ', $v) : (string) $v, $answers));
        $spam = now()->timestamp - $this->shownAt < 3 || preg_match_all('#https?://|www\.#i', $text) > 2;

        Forms::receive($form, $answers, $this->page, $spam);
        $this->finish();
    }

    protected function finish(): void
    {
        $this->blank();
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
        $form = Forms::find($this->handle);

        return view('livewire.site.made-form', [
            'form' => $form,
            'fields' => $form ? Forms::fields($form) : [],
            'open' => $form && Forms::open($form),
        ]);
    }
}
