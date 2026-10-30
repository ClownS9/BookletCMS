<?php

namespace App\Livewire\Site;

use App\Models\Reader;
use App\Support\ReaderAccounts;
use App\Support\Readers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A reader signs in with their email and password. Five wrong tries and
 * that address (from that place) waits a while. The same "didn't match"
 * whether the address has an account or not.
 */
class ReaderSignIn extends Component
{
    use \App\Livewire\Concerns\Guarded;

    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    /** Where they were going (a members-only page), checked to be on this site. */
    #[Locked]
    public ?string $back = null;

    public function mount(): void
    {
        $this->back = Readers::safeBack((string) request()->query('back', session('readers.back', '')));
    }

    public function signIn(): void
    {
        $this->email = mb_strtolower(trim($this->email));
        $this->validate(['email' => ['required', 'email:rfc', 'max:190'], 'password' => ['required', 'string', 'max:200']], [], ['email' => __('email address'), 'password' => __('password')]);

        $key = 'reader-signin:'.sha1($this->email.'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', __('Too many tries. Wait :minutes minutes, or make a new password.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]));

            return;
        }
        if (! $this->guarded('reader-sign-in', ['email' => $this->email])) {
            return;
        }

        $reader = Reader::query()->where('email', $this->email)->first();
        // (A hash is checked even without an account, so the answer takes as long either way.)
        $ok = Hash::check($this->password, $reader->password ?? Hash::make(\Illuminate\Support\Str::random(20)));
        if (! $reader || ! $ok || $reader->state === 'blocked') {
            RateLimiter::hit($key, 900);
            $this->reset('password');
            $this->addError('email', __('That email and password don’t match an account here.'));

            return;
        }
        RateLimiter::clear($key);

        ReaderAccounts::signIn($reader, $this->remember);
        // A group's invitation link they followed before signing in: in they go.
        if ($group = ReaderAccounts::groupInvite()) {
            ReaderAccounts::addTo($group, $reader);
            ReaderAccounts::forgetGroupInvite();
        }
        session()->forget('readers.back');

        $this->redirect($this->back ?? Readers::landing());
    }

    public function render()
    {
        return view('livewire.site.readers.sign-in', ['canJoin' => in_array(Readers::joining(), ['open', 'approval'], true) || ReaderAccounts::groupInvite() !== null]);
    }
}
