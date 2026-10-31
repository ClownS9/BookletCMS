<div style="display: contents">
    {{-- Left page: where email goes, and the services --}}
    <section class="page page--left page--list" style="flex-basis: 360px">
        <div class="page-head">
            <x-admin.crumbs>
                <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
            </x-admin.crumbs>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Email') }}</h1>
        </div>
        <x-admin.card>
            <p class="lede" style="margin: 12px 0 14px; font-size: 15px">{{ __('The mail server the book sends through: password resets, comment alerts and new posts to subscribers.') }}</p>

            <p @class(['mail-status', 'mail-status--'.$status['state']]) role="status">
                <span @class(['svc-light', 'svc-light--on' => $status['state'] !== 'off']) aria-hidden="true"></span>
                <span>{{ __($status['text']) }}@if ($status['state'] === 'on' && $record?->tested_at) <small>{{ __('Last test went through :when.', ['when' => $record->tested_at->diffForHumans()]) }}</small>@endif</span>
            </p>
        </x-admin.card>

        <x-admin.card>
            <h2 class="eyebrow section-label" style="margin-top: 18px">{{ __('Your email service') }}</h2>
            <ul class="picks">
                @foreach ($services as $key => $s)
                    <li wire:key="mail-{{ $key }}">
                        <button type="button" class="pick" wire:click="pick('{{ $key }}')" aria-pressed="{{ $service === $key ? 'true' : 'false' }}" style="align-items: center">
                            <span @class(['svc-light', 'svc-light--on' => $record?->isReady() && $record->service === $key]) aria-hidden="true"></span>
                            <span style="flex: 1 1 auto; min-width: 0">
                                <span class="pick__title" style="font-size: 17px">{{ $s['name'] }}</span>
                                <span class="pick__meta" style="overflow-wrap: anywhere">{{ $s['host'] ?: __('Type its details') }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the server --}}
    <form wire:submit="save" class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            <div class="ribbon-clear">
                <h2 class="heading">{{ $info['name'] }}</h2>
                <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">
                    {{ __($info['note']) }}
                    @if ($info['url']) <a href="{{ $info['url'] }}" target="_blank" rel="noopener">{{ $info['url_label'] }} →</a> @endif
                </p>
            </div>

            <div class="setting-rows">
                <div class="setting-row">
                    <span>
                        <span id="mail-enabled" style="display: block; font-size: 15px; font-weight: 500">{{ __('Send email with these settings') }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Off: the server’s own .env settings are used, if it has any.') }}</span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-labelledby="mail-enabled"
                            aria-checked="{{ $enabled ? 'true' : 'false' }}" wire:click="$toggle('enabled')"></button>
                </div>
            </div>

            <div class="two-up two-up--server">
                <div class="field">
                    <label for="mail-host">{{ __('Mail server') }}</label>
                    <input id="mail-host" type="text" wire:model="host" placeholder="{{ __('smtp.example.com') }}" autocomplete="off" spellcheck="false" style="font-family: var(--mono); font-size: 13px">
                    @error('host') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="mail-port">{{ __('Port') }}</label>
                    <input id="mail-port" type="text" inputmode="numeric" wire:model.live.debounce.500ms="port" placeholder="587" style="font-family: var(--mono); font-size: 13px">
                    @error('port') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="field">
                <label for="mail-security">{{ __('Security') }}</label>
                <select id="mail-security" wire:model="security">
                    @foreach ($securities as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
                @if ($security === 'none')
                    <p class="field__hint">{{ __('Passwords and emails would travel unencrypted. Only for a mail server on this same machine or network.') }}</p>
                @endif
            </div>

            <div class="two-up">
                <div class="field">
                    <label for="mail-username">{{ __('Username') }}</label>
                    <input id="mail-username" type="text" wire:model="username" autocomplete="off" spellcheck="false">
                    @error('username') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="mail-password">{{ __('Password') }}</label>
                    <input id="mail-password" type="password" wire:model="password" autocomplete="new-password"
                           placeholder="{{ $record?->passwordLost() ? __('Type it again') : ($hasPassword ? __('Saved · type to replace') : '') }}">
                    @if ($record?->passwordLost())
                        <p class="field__error">{{ __('The saved password can’t be read any more (the site’s APP_KEY changed). Type it again and save.') }}</p>
                    @endif
                    @error('password') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="field__hint" style="margin-top: -6px">
                {{ __('The password is stored encrypted in the database, never shown again and left out of backups.') }}
                @if ($hasPassword) <button type="button" class="link-button" style="min-height: 0; text-decoration: underline; font-size: 12px" wire:click="forgetPassword" wire:confirm="{{ __('Remove the saved password?') }}">{{ __('Remove saved password') }}</button> @endif
            </p>

            <div class="two-up">
                <div class="field">
                    <label for="mail-from">{{ __('Send from') }}</label>
                    <input id="mail-from" type="email" wire:model="fromAddress" placeholder="hello@yoursite.com" autocomplete="off">
                    @error('fromAddress') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="mail-from-name">{{ __('From name') }}</label>
                    <input id="mail-from-name" type="text" wire:model="fromName" placeholder="{{ config('app.name') }}">
                    @error('fromName') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="field__hint" style="margin-top: -6px">{{ __('Use an address your service lets you send from, usually the one you sign in with or one on your own domain.') }}</p>

            @if ($notice)
                <p @class(['notice', 'notice--error' => $noticeIsError]) role="status">{{ $notice }}</p>
            @endif

            <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
                <div style="display: flex; gap: 10px; flex-wrap: wrap">
                    <button type="submit" class="btn btn--ink btn--lg">{{ __('Save') }}</button>
                    <button type="button" class="btn" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                        <span wire:loading.remove wire:target="sendTest">{{ __('Send a test email') }}</span>
                        <span wire:loading wire:target="sendTest">{{ __('Sending…') }}</span>
                    </button>
                </div>
                <x-admin.folio side="right" style="margin: 0; padding: 0" />
            </div>
        </x-admin.card>

    </form>
</div>
