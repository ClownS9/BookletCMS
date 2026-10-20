<?php

namespace App\Livewire\Site;

use App\Support\Newsletter;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * "New posts by email": a reader leaves their address, then confirms from
 * their inbox before anything is sent.
 */
class Subscribe extends Component
{
    public string $email = '';

    /** Honeypot: people never see it, bots fill it in. */
    public string $website = '';

    public ?string $done = null;

    public string $place = 'post';

    public function subscribe(): void
    {
        if (! Newsletter::enabled()) {
            return;
        }
        if ($this->website !== '') {
            $this->done = 'sent'; // a bot: pretend, keep nothing

            return;
        }

        $this->email = mb_strtolower(trim($this->email));
        $this->validate(['email' => ['required', 'email:rfc', 'max:190']], [], ['email' => 'email address']);

        $key = 'subscribe:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'That’s a lot of tries. Please wait a few minutes.');

            return;
        }
        RateLimiter::hit($key, 900);

        $this->done = Newsletter::subscribe($this->email);
    }

    public function render()
    {
        return view('livewire.site.subscribe', ['open' => Newsletter::enabled()]);
    }
}
