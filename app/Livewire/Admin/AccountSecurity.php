<?php

namespace App\Livewire\Admin;

use App\Booklet\Site;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Support\Devices;
use App\Support\PasskeyProblem;
use App\Support\Passkeys;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Your account › Signing in: passkeys, two-step sign-in with an
 * authenticator app, and the devices you're signed in on.
 */
class AccountSecurity extends Component
{
    use Stamps;

    /** The secret being set up (shown as a QR code; saved only once a code proves it works). */
    #[Locked]
    public ?string $setupSecret = null;

    public string $setupCode = '';

    /** Recovery codes, shown once right after they're made. */
    #[Locked]
    public array $freshCodes = [];

    /** Naming a new passkey: the password has been checked, the device is next. */
    #[Locked]
    public bool $addingPasskey = false;

    public string $passkeyName = '';

    /** "codes", "off" or "passkey": what the password is being asked for. */
    #[Locked]
    public ?string $asking = null;

    public string $password = '';

    public function start(): void
    {
        $this->setupSecret = TwoFactor::newSecret();
        $this->setupCode = '';
        $this->resetValidation();
    }

    public function cancelSetup(): void
    {
        $this->setupSecret = null;
        $this->setupCode = '';
        $this->resetValidation();
    }

    public function confirm(): void
    {
        $this->resetErrorBag(); // clear the last try's message

        if (! $this->setupSecret) {
            return;
        }

        if (TwoFactor::matchStep($this->setupSecret, $this->setupCode) === null) {
            $this->addError('setupCode', __('That code doesn’t match. Check the time on your phone, and type the newest code.'));

            return;
        }

        $this->freshCodes = TwoFactor::enable(request()->user(), $this->setupSecret);
        $this->setupSecret = null;
        $this->setupCode = '';
        ActivityLog::record('turned on two-step sign-in', 'people', 'user', (string) request()->user()?->id, request()->user()->name);
        $this->stamp('Two-step sign-in is on.');
    }

    public function doneWithCodes(): void
    {
        $this->freshCodes = [];
    }

    public function ask(string $what): void
    {
        $this->asking = in_array($what, ['codes', 'off', 'passkey'], true) ? $what : null;
        $this->password = '';
        $this->resetValidation();
    }

    public function answer(): void
    {
        $this->resetErrorBag(); // clear the last try's message

        $user = request()->user();

        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', __('That isn’t your password.'));

            return;
        }

        if ($this->asking === 'codes') {
            $this->freshCodes = TwoFactor::regenerateRecoveryCodes($user);
            $this->stamp('New recovery codes. The old ones no longer work.');
        } elseif ($this->asking === 'off') {
            TwoFactor::disable($user);
            ActivityLog::record('turned off two-step sign-in', 'people', 'user', (string) $user->id, $user->name);
            \App\Notifications\SecurityNotice::send($user, 'two_factor_off', $user->name);
            $this->stamp('Two-step sign-in is off.');
        } elseif ($this->asking === 'passkey') {
            session()->put('auth.password_confirmed_at', time());
            $this->addingPasskey = true;
            $this->passkeyName = Passkeys::suggestName(request());
        }

        $this->asking = null;
        $this->password = '';
    }

    /** Adding a passkey asks for the password first, unless it was typed in the last while. */
    public function startPasskey(): void
    {
        if (! $this->confirmedRecently()) {
            $this->ask('passkey');

            return;
        }
        $this->asking = null;
        $this->addingPasskey = true;
        $this->passkeyName = Passkeys::suggestName(request());
        $this->resetValidation();
    }

    public function cancelPasskey(): void
    {
        $this->addingPasskey = false;
        $this->passkeyName = '';
        $this->resetValidation();
    }

    /** What the browser hands the device to make a passkey (empty when it isn't the moment). */
    public function passkeyOptions(): array
    {
        $this->resetErrorBag();
        $this->validate(['passkeyName' => ['required', 'string', 'max:80']], [], ['passkeyName' => __('name')]);
        if (! $this->addingPasskey || ! $this->confirmedRecently()) {
            $this->cancelPasskey();

            return [];
        }

        return Passkeys::options(request()->user(), request());
    }

    /** What the device made: check it and keep it. */
    public function addPasskey(array $credential): void
    {
        $user = request()->user();
        if (! $this->addingPasskey || ! $this->confirmedRecently()) {
            $this->cancelPasskey();

            return;
        }
        $this->validate(['passkeyName' => ['required', 'string', 'max:80']], [], ['passkeyName' => __('name')]);

        try {
            $passkey = Passkeys::register($user, $credential, $this->passkeyName, request());
        } catch (PasskeyProblem $e) {
            $this->addError('passkey', __($e->getMessage()));

            return;
        }

        ActivityLog::record('added a passkey', 'people', 'user', (string) $user->id, $user->name);
        \App\Notifications\SecurityNotice::send($user, 'passkey', $user->name, $passkey->name);
        $this->cancelPasskey();
        $this->stamp('Passkey added. Next time, sign in with it.');
    }

    /** The browser's side didn't work (cancelled, unsupported…): say why. */
    public function passkeyFailed(string $why): void
    {
        $this->resetErrorBag();
        $this->addError('passkey', Passkeys::failure($why, true));
    }

    public function removePasskey(int $id): void
    {
        $user = request()->user();
        $passkey = $user->passkeys()->whereKey($id)->first();
        if (! $passkey) {
            return;
        }
        $passkey->delete();
        ActivityLog::record('removed a passkey', 'people', 'user', (string) $user->id, $user->name);
        $this->stamp('Passkey removed. Also delete it from the device’s password manager.');
    }

    protected function confirmedRecently(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) <= (int) config('auth.password_timeout', 10800);
    }

    public function signOut(string $id): void
    {
        $this->stamp(Devices::signOut(request()->user(), $id) ? 'Signed out on that device.' : 'That device was already signed out.');
    }

    public function signOutOthers(): void
    {
        $n = Devices::signOutOthers(request()->user());
        $this->stamp($n ? trans_choice('Signed out on :count other device.|Signed out on :count other devices.', $n) : 'You’re only signed in here.');
    }

    public function render()
    {
        $user = request()->user();

        return view('livewire.admin.account-security', [
            'on' => TwoFactor::on($user),
            'since' => $user->two_factor_confirmed_at,
            'codesLeft' => count(TwoFactor::recoveryCodesOf($user)),
            'uri' => $this->setupSecret ? TwoFactor::uri($this->setupSecret, $user->email, app(Site::class)->name() ?: 'Booklet') : null,
            'devices' => Devices::for($user),
            'devicesAvailable' => Devices::available(),
            'passkeys' => $user->passkeys()->latest()->get(),
        ]);
    }
}
