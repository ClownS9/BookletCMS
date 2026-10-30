<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\MailSetting;
use App\Support\MailSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings › Email: the mail server the book sends through (password
 * resets, comment alerts, new posts to subscribers), so nobody has to edit
 * .env. Admins only. The password is stored encrypted and never shown again.
 */
#[Layout('components.layouts.admin')]
#[Title('Email')]
class EmailSettings extends Component
{
    public bool $enabled = false;

    public string $service = 'gmail';

    public string $host = '';

    public string $port = '587';

    public string $security = 'tls';

    public string $username = '';

    /** A newly typed password; blank means keep the saved one. */
    public string $password = '';

    public string $fromAddress = '';

    public string $fromName = '';

    public ?string $notice = null;

    public bool $noticeIsError = false;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $record = MailSettings::record();
        if ($record) {
            $this->enabled = $record->enabled;
            $this->service = array_key_exists($record->service, MailSettings::SERVICES) ? $record->service : 'other';
            $this->host = (string) $record->host;
            $this->port = (string) ($record->port ?: '');
            $this->security = $record->security;
            $this->username = (string) $record->username;
            $this->fromAddress = (string) $record->from_address;
            $this->fromName = (string) $record->from_name;
        } else {
            $this->usePreset('gmail');
            $this->fromAddress = (string) request()->user()->email;
            $this->fromName = (string) app(\App\Booklet\Site::class)->name();
        }
    }

    /** Picking a service fills in its server details. */
    public function pick(string $service): void
    {
        abort_unless(array_key_exists($service, MailSettings::SERVICES), 404);
        $this->resetErrorBag();
        $this->notice = null;
        $this->usePreset($service);
    }

    protected function usePreset(string $service): void
    {
        $preset = MailSettings::SERVICES[$service];
        $this->service = $service;
        if ($service !== 'other' || $this->host === '') {
            $this->host = $preset['host'];
        }
        $this->port = (string) $preset['port'];
        $this->security = $preset['security'];
        if ($preset['username'] !== '') {
            $this->username = $preset['username'];
        } elseif ($this->username === 'apikey') {
            $this->username = '';
        }
    }

    /** Typing a usual port picks the matching security. */
    public function updatedPort(): void
    {
        $this->security = match (trim($this->port)) {
            '465' => 'ssl',
            '587' => $this->security === 'ssl' ? 'tls' : $this->security,
            default => $this->security,
        };
    }

    protected function rules(): array
    {
        return [
            'service' => ['required', 'in:'.implode(',', array_keys(MailSettings::SERVICES))],
            'host' => [$this->enabled ? 'required' : 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$|^\[?[0-9a-fA-F:.]+\]?$/'],
            'port' => [$this->enabled ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'security' => ['required', 'in:'.implode(',', array_keys(MailSettings::SECURITY))],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'fromAddress' => [$this->enabled ? 'required' : 'nullable', 'email', 'max:190'],
            'fromName' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['host' => 'mail server', 'fromAddress' => 'from address', 'fromName' => 'from name'];
    }

    protected function messages(): array
    {
        return ['host.regex' => 'Just the server’s name, like smtp.example.com (no https:// or slashes).'];
    }

    /** The settings on the form (not saved), with the saved password if none was typed. */
    protected function draft(): MailSetting
    {
        $saved = MailSettings::record();

        return new MailSetting([
            'enabled' => $this->enabled,
            'service' => $this->service,
            'host' => strtolower(trim($this->host)) ?: null,
            'port' => $this->port !== '' ? (int) $this->port : null,
            'security' => $this->security,
            'username' => trim($this->username) ?: null,
            'password' => $this->password !== '' ? $this->password : $saved?->password,
            'from_address' => mb_strtolower(trim($this->fromAddress)) ?: null,
            'from_name' => trim($this->fromName) ?: null,
        ]);
    }

    public function save(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->validate();

        if ($this->enabled && $this->password === '' && MailSettings::record()?->passwordLost()) {
            $this->addError('password', __('Type the password again: the saved one can’t be read since the site’s APP_KEY changed.'));

            return;
        }

        $draft = $this->draft();
        $record = MailSettings::record() ?? new MailSetting;
        $changedServer = $record->exists && ($record->host !== $draft->host || $record->port !== $draft->port
            || $record->username !== $draft->username || $this->password !== '' || $record->from_address !== $draft->from_address);

        $record->fill($draft->only(['enabled', 'service', 'host', 'port', 'security', 'username', 'from_address', 'from_name']));
        if ($this->password !== '') {
            $record->password = $this->password;
        }
        if ($changedServer) {
            $record->tested_at = null; // the old test was for other settings
        }
        $record->save();

        ActivityLog::record($record->enabled ? 'set the book to send email through' : 'switched off the email settings', 'settings', 'settings', 'email', $record->enabled ? (string) $record->host : null);

        $this->password = '';
        $this->notice = $record->enabled
            ? ($record->tested_at ? 'Saved. The book sends email through '.$record->host.'.' : 'Saved. Send a test email to be sure it works.')
            : 'Saved, and switched off. '.(MailSettings::status()['state'] === 'env' ? 'The server’s own (.env) settings are used.' : 'Emails won’t be sent until it’s on.');
        $this->noticeIsError = false;
    }

    public function sendTest(): void
    {
        $this->resetErrorBag();
        abort_unless(request()->user()->isAdmin(), 403);

        $this->validate(array_merge($this->rules(), [
            'host' => array_merge(['required'], array_slice($this->rules()['host'], 1)),
            'port' => ['required', 'integer', 'between:1,65535'],
            'fromAddress' => ['required', 'email', 'max:190'],
        ]));

        $limit = 'mail-test|'.request()->user()?->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limit, 6)) {
            $this->notice = 'That’s a lot of tests. Wait a minute and try again.';
            $this->noticeIsError = true;

            return;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($limit, 60);

        $draft = $this->draft();
        $to = (string) request()->user()->email;
        $problem = MailSettings::test($draft, $to);

        if ($problem) {
            $this->notice = $problem;
            $this->noticeIsError = true;

            return;
        }

        // The test was for exactly what's saved: remember it went through.
        $saved = MailSettings::record();
        if ($saved && $this->password === '' && $saved->host === $draft->host && $saved->port === $draft->port
            && $saved->username === $draft->username && $saved->from_address === $draft->from_address && $saved->security === $draft->security) {
            $saved->forceFill(['tested_at' => now()])->save();
        }

        $this->notice = 'Sent to '.$to.'. Check your inbox (and the spam folder, the first time).'
            .($saved?->isReady() ? '' : ' Switch it on and save to use these settings.');
        $this->noticeIsError = false;
    }

    public function forgetPassword(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        MailSettings::record()?->forceFill(['password' => null, 'tested_at' => null])->save();
        $this->password = '';
        $this->notice = 'Password removed.';
        $this->noticeIsError = false;
    }

    public function render()
    {
        $record = MailSettings::record();

        return view('livewire.admin.email-settings', [
            'services' => MailSettings::SERVICES,
            'info' => MailSettings::SERVICES[$this->service] ?? MailSettings::SERVICES['other'],
            'securities' => MailSettings::SECURITY,
            'record' => $record,
            'status' => MailSettings::status(),
            'hasPassword' => (bool) $record?->password,
        ]);
    }
}
