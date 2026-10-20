<?php

namespace App\Livewire\Admin;

use App\Booklet\Site;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Support\Devices;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Your account › Signing in: two-step sign-in with an authenticator app,
 * and the devices you're signed in on.
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

    /** "codes" or "off": what the password is being asked for. */
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
            $this->addError('setupCode', 'That code doesn’t match. Check the time on your phone, and type the newest code.');

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
        $this->asking = in_array($what, ['codes', 'off'], true) ? $what : null;
        $this->password = '';
        $this->resetValidation();
    }

    public function answer(): void
    {
        $this->resetErrorBag(); // clear the last try's message

        $user = request()->user();

        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', 'That isn’t your password.');

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
        }

        $this->asking = null;
        $this->password = '';
    }

    public function signOut(string $id): void
    {
        $this->stamp(Devices::signOut(request()->user(), $id) ? 'Signed out on that device.' : 'That device was already signed out.');
    }

    public function signOutOthers(): void
    {
        $n = Devices::signOutOthers(request()->user());
        $this->stamp($n ? 'Signed out on '.$n.' other '.str('device')->plural($n).'.' : 'You’re only signed in here.');
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
        ]);
    }
}
