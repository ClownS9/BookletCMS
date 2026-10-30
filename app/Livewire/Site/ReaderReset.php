<?php

namespace App\Livewire\Site;

use App\Models\Reader;
use App\Support\ReaderAccounts;
use App\Support\Readers;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** A new password, from the link in the email. Signs them in when it's done. */
class ReaderReset extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = mb_strtolower((string) request()->query('email', ''));
    }

    public function save(): void
    {
        $this->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', new \App\Rules\StrongPassword([$this->email])],
        ], [], ['email' => __('email address'), 'password' => __('password')]);

        $done = null;
        $status = Password::broker('readers')->reset(
            ['email' => mb_strtolower(trim($this->email)), 'password' => $this->password, 'token' => $this->token],
            function (Reader $reader, string $password) use (&$done) {
                $reader->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                // The link came to their inbox, so the address is theirs.
                if (! $reader->confirmed()) {
                    ReaderAccounts::confirm($reader);
                }
                $done = $reader;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $done) {
            $this->addError('email', __('That link has expired or was already used. Ask for a new one.'));

            return;
        }
        if ($done->state !== 'blocked') {
            ReaderAccounts::signIn($done);
        }
        $this->redirect(Readers::landing());
    }

    public function render()
    {
        return view('livewire.site.readers.reset');
    }
}
