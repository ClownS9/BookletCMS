<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.cover')]
#[Title('Open your book')]
class Login extends Component
{
    use \App\Livewire\Concerns\Guarded;

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    /** A hash nobody's password matches, checked when the email isn't known (same time either way). */
    protected const NOBODY = '$2y$12$IlOtP2lmSXyF0QfJhDBJJ.iVdlWjk7.tengEZCddn3ae1m8tMzSeq';

    public bool $remember = true;

    /** "signin", "code" (two-step sign-in) or "forgot" (Lost your place?). */
    public string $mode = 'signin';

    /** The 6-digit code from their authenticator app, or a recovery code. */
    public string $code = '';

    public bool $usingRecovery = false;

    public string $resetEmail = '';

    public bool $sent = false;

    public function mount()
    {
        // A brand new book has nobody to sign in yet.
        if (! User::query()->exists()) {
            return redirect()->route('setup');
        }
    }

    public function showForgot(): void
    {
        $this->mode = 'forgot';
        $this->resetEmail = $this->email;
        $this->sent = false;
        $this->resetValidation();
    }

    public function showSignIn(): void
    {
        $this->mode = 'signin';
        $this->resetValidation();
    }

    public function sendResetLink(): void
    {
        $this->validate(['resetEmail' => ['required', 'email']], [], ['resetEmail' => 'email']);
        if (! $this->guarded('reset', ['email' => $this->resetEmail])) {
            return;
        }

        $throttle = 'reset|'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttle, 3)) {
            throw ValidationException::withMessages([
                'resetEmail' => 'Too many tries. Wait '.RateLimiter::availableIn($throttle).' seconds and try again.',
            ]);
        }
        RateLimiter::hit($throttle, 300);

        // The same answer either way, so nobody can test which emails have accounts.
        Password::sendResetLink(['email' => $this->resetEmail]);
        $this->sent = true;
    }

    public function login()
    {
        $this->validate();

        if (! $this->guarded('login', ['email' => $this->email])) {
            return null;
        }

        $throttle = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many tries. Wait '.RateLimiter::availableIn($throttle).' seconds and try again.',
            ]);
        }

        $this->resetValidation();

        // Check the password without signing in yet: with two-step sign-in on,
        // nobody is signed in until the code from their app is right too.
        $provider = Auth::getProvider();
        $email = trim($this->email);
        $user = $provider->retrieveByCredentials(['email' => $email])
            ?? ($email !== mb_strtolower($email) ? $provider->retrieveByCredentials(['email' => mb_strtolower($email)]) : null);

        if (! $user) {
            // Take as long as a real check, so the answer doesn't give away which emails have accounts.
            \Illuminate\Support\Facades\Hash::check($this->password, self::NOBODY);
        }

        if (! $user instanceof User || ! $provider->validateCredentials($user, ['password' => $this->password])) {
            RateLimiter::hit($throttle);

            throw ValidationException::withMessages(['email' => 'That email and password don’t match.']);
        }

        RateLimiter::clear($throttle);
        $this->password = '';

        if (TwoFactor::on($user)) {
            session()->put('login.challenge', ['id' => $user->getAuthIdentifier(), 'remember' => $this->remember, 'until' => now()->addMinutes(5)->timestamp]);
            $this->mode = 'code';
            $this->code = '';
            $this->usingRecovery = false;

            return null;
        }

        return $this->signIn($user, $this->remember);
    }

    /** The second step: the code from their app (or a recovery code). */
    public function verifyCode()
    {
        $this->resetErrorBag(); // clear the last try's message

        $challenge = session('login.challenge');
        if (! $challenge || $challenge['until'] < now()->timestamp || ! ($user = User::find($challenge['id']))) {
            session()->forget('login.challenge');
            $this->mode = 'signin';
            $this->addError('email', 'That took a while, so please sign in again.');

            return null;
        }

        $throttle = 'two-factor|'.$user->id.'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Wait '.RateLimiter::availableIn($throttle).' seconds and try again.']);
        }

        $how = TwoFactor::check($user, $this->code);
        if (! $how) {
            RateLimiter::hit($throttle, 60);

            throw ValidationException::withMessages(['code' => $this->usingRecovery
                ? 'That recovery code doesn’t match (each one works once).'
                : 'That code doesn’t match. Check the time on your phone, and use the newest code.']);
        }

        RateLimiter::clear($throttle);
        session()->forget('login.challenge');

        if ($how === 'recovery') {
            $left = count(TwoFactor::recoveryCodesOf($user->fresh()));
            session()->flash('stamp', 'Signed in with a recovery code. '.$left.' '.Str::plural('code', $left).' left: you can make new ones in Your account.');
        }

        return $this->signIn($user, (bool) $challenge['remember']);
    }

    public function toggleRecovery(): void
    {
        $this->usingRecovery = ! $this->usingRecovery;
        $this->code = '';
        $this->resetValidation();
    }

    public function cancelCode(): void
    {
        session()->forget('login.challenge');
        $this->mode = 'signin';
        $this->code = '';
        $this->resetValidation();
    }

    protected function signIn($user, bool $remember)
    {
        Auth::login($user, $remember);
        session()->regenerate();
        session()->put('stacy.greet', 'login'); // Stacy says hello on the first page

        return $this->redirectIntended(route('admin.contents'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
