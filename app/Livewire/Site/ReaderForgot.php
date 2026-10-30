<?php

namespace App\Livewire\Site;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/** "Forgotten your password?": a link by email. The same answer whether the address has an account or not. */
class ReaderForgot extends Component
{
    public string $email = '';

    public bool $sent = false;

    public function send(): void
    {
        $this->email = mb_strtolower(trim($this->email));
        $this->validate(['email' => ['required', 'email:rfc', 'max:190']], [], ['email' => __('email address')]);
        $key = 'reader-forgot:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', __('That’s a lot of tries. Please wait a few minutes.'));

            return;
        }
        RateLimiter::hit($key, 900);
        rescue(fn () => Password::broker('readers')->sendResetLink(['email' => $this->email]), null, false);
        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.site.readers.forgot');
    }
}
