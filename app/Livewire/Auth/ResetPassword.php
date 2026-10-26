<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Setting a new password from the emailed link.
 */
#[Layout('components.layouts.cover')]
#[Title('A new password')]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword()
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', new \App\Rules\StrongPassword([$this->email])],
        ], ['password.confirmed' => __('The two passwords don’t match.')]);

        $status = Password::reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
                // Whoever had the old password is signed out everywhere.
                \App\Support\Devices::signOutEverywhere($user);
                \App\Notifications\SecurityNotice::send($user, 'password_reset', $user->name);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('That link has expired or doesn’t match this email. Ask for a new one.')]);
        }

        session()->flash('stamp', __('Password changed. Sign in with the new one.'));

        return $this->redirectRoute('login', navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
