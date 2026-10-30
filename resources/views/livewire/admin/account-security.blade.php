<div class="security" id="security">
    {{-- Passkeys --}}
    <div class="security__block" x-data="bookletPasskey('make')">
        <h2 class="heading" style="display: flex; align-items: center; gap: 10px"><x-admin.icon name="key" size="22" /> {{ __('Passkeys') }}</h2>
        <p style="font-size: 15px; color: var(--muted); margin: 0">{{ __('Sign in with your face, fingerprint or device PIN instead of typing a password: Face ID, Touch ID, Windows Hello, an Android phone or a security key. Nothing about your face or finger leaves the device.') }}</p>

        @if ($passkeys->isNotEmpty())
            <ul class="devices passkeys">
                @foreach ($passkeys as $p)
                    <li wire:key="passkey-{{ $p->id }}">
                        <span class="devices__icon" aria-hidden="true"><x-admin.icon name="key" size="18" /></span>
                        <span class="devices__text">
                            <strong>{{ $p->name }}</strong>
                            <span>{{ __('Added :when', ['when' => $p->created_at?->translatedFormat('j F Y')]) }} · {{ $p->last_used_at ? __('last used :when', ['when' => $p->last_used_at->diffForHumans()]) : __('not used yet') }}</span>
                        </span>
                        <button type="button" class="link-button bin-list__forget" wire:click="removePasskey({{ $p->id }})"
                                wire:confirm="{{ __('Remove the passkey “:name”? It won’t open the book any more.', ['name' => $p->name]) }}">{{ __('Remove') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($asking === 'passkey')
            <form wire:submit="answer" class="field" style="max-width: 320px">
                <x-admin.password id="passkey-password" model="password" autocomplete="current-password" autofocus class="pw-field--bare" :label="__('Your password, to add a passkey')" />
                <div style="display: flex; gap: 10px; margin-top: 6px">
                    <button type="submit" class="btn btn--ink">{{ __('Continue') }}</button>
                    <button type="button" class="btn btn--quiet" wire:click="ask('')">{{ __('Cancel') }}</button>
                </div>
            </form>
        @elseif ($addingPasskey)
            <form class="field" style="max-width: 380px" x-on:submit.prevent="go()" x-init="prepare()">
                <label for="passkey-name">{{ __('A name, so you know which device it is') }}</label>
                <input id="passkey-name" type="text" class="input" wire:model="passkeyName" maxlength="80" autofocus>
                @error('passkeyName') <p class="field__error">{{ $message }}</p> @enderror
                <div style="display: flex; gap: 10px; margin-top: 6px; flex-wrap: wrap">
                    <button type="submit" class="btn btn--ink" :disabled="busy">
                        <span x-show="!busy">{{ __('Make the passkey') }}</span><span x-show="busy" x-cloak>{{ __('Waiting for your device…') }}</span>
                    </button>
                    <button type="button" class="btn btn--quiet" wire:click="cancelPasskey">{{ __('Cancel') }}</button>
                </div>
                <p class="field__hint">{{ __('Your browser asks where to keep it: this device, your phone, or a password manager.') }}</p>
            </form>
        @else
            <button type="button" class="btn" style="align-self: flex-start" wire:click="startPasskey">{{ __('Add a passkey') }}</button>
            <p class="field__hint" x-show="!available" x-cloak style="margin: 0">{{ __('This browser can’t make passkeys here. They need a recent browser and a secure (https) address: see below for what’s missing.') }}</p>
        @endif
        @error('passkey') <p class="field__error" role="alert">{{ $message }}</p> @enderror

        @php
            $pkWords = [
                'secure' => __('Secure address'),
                'secureOk' => __(':address is secure.'),
                'secureNo' => __(':address isn’t secure, so the browser won’t make passkeys here. Use https, or open the book at http://localhost while trying it on your own computer.'),
                'named' => __('An address with a name'),
                'namedOk' => __(':host is a name, as passkeys need.'),
                'namedNo' => __(':host is numbers, and passkeys need a name. Open :suggest instead.'),
                'browser' => __('This browser'),
                'browserOk' => __('It can use passkeys.'),
                'browserNo' => __('It can’t use passkeys. Try a recent Safari, Chrome, Edge or Firefox.'),
                'device' => __('A check on this device'),
                'deviceOk' => __('This device can check it’s you itself: Touch ID, Face ID or Windows Hello.'),
                'deviceNo' => __('No Touch ID, Face ID or Windows Hello found. On a Mac: System Settings › Touch ID & Password, add a fingerprint. You can still use your phone or a security key.'),
                'server' => __('The server sees the same address'),
                'serverOk' => __('Yes, :address.'),
                'serverNo' => __('The browser is at :browser, but the server thinks it is at :server, so it would refuse the passkey. Behind a proxy that handles https? Add TRUSTED_PROXIES=* to .env.'),
            ];
        @endphp
        {{-- Is this device ready? Opens by itself when something is wrong. --}}
        <details class="pk-check" x-data="bookletPasskeyCheck(@js(request()->getSchemeAndHttpHost()))" x-on:toggle="if ($el.open) run()"
                 @if ($errors->has('passkey')) open @endif>
            <summary>{{ __('Is this device ready for passkeys?') }}</summary>
            <ul class="pk-check__list" aria-live="polite">
                <template x-for="row in rows" :key="row.key">
                    <li :class="'pk-check__row pk-check__row--' + row.state">
                        <span class="pk-check__mark" aria-hidden="true" x-text="row.state === 'ok' ? '✓' : (row.state === 'no' ? '✕' : '!')"></span>
                        <span><strong x-text="row.title"></strong> <span x-text="row.text"></span></span>
                    </li>
                </template>
            </ul>
            <p class="field__hint" x-show="rows.length && rows.every((r) => r.state === 'ok')" x-cloak style="margin: 8px 0 0">{{ __('All set. Press Add a passkey, and your device asks for your face, finger or PIN.') }}</p>
            <script type="application/json" data-pk-words>{!! json_encode($pkWords, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
        </details>

        @if ($passkeys->isNotEmpty() && $on)
            <p class="field__hint" style="margin: 0">{{ __('A passkey checks it’s you on the device itself, so it skips the code from your app too.') }}</p>
        @endif
    </div>

    {{-- Two-step sign-in --}}
    <div class="security__block">
        <h2 class="heading" style="display: flex; align-items: center; gap: 10px"><x-admin.icon name="lock" size="22" /> {{ __('Two-step sign-in') }}</h2>

        @if ($freshCodes)
            {{-- Recovery codes: shown once --}}
            <div class="recovery">
                <p><strong>{{ __('Your recovery codes.') }}</strong> {{ __('If your phone is lost, each of these lets you in once. Keep them somewhere safe, like a password manager. You won’t see them again.') }}</p>
                <ol class="recovery__codes">
                    @foreach ($freshCodes as $c) <li><code>{{ $c }}</code></li> @endforeach
                </ol>
                <div style="display: flex; gap: 10px; flex-wrap: wrap"
                     x-data="{ copied: false, text: @js(implode("\n", $freshCodes)) }">
                    <button type="button" class="btn btn--sm" x-on:click="navigator.clipboard.writeText(text); copied = true; setTimeout(() => copied = false, 1500)">
                        <span x-show="!copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </button>
                    <a class="btn btn--sm" :href="'data:text/plain;charset=utf-8,' + encodeURIComponent('Recovery codes for {{ addslashes(app(\App\Booklet\Site::class)->name()) }} ({{ request()->user()->email }})\n\n' + text)" download="booklet-recovery-codes.txt">{{ __('Download') }}</a>
                    <button type="button" class="btn btn--sm btn--ink" wire:click="doneWithCodes">{{ __('I’ve saved them') }}</button>
                </div>
            </div>
        @elseif ($setupSecret)
            {{-- Setting it up --}}
            <ol class="setup-2fa">
                <li>
                    <strong>{{ __('Scan this with your authenticator app') }}</strong>
                    <span>{{ __('Google Authenticator, 1Password, Authy, Microsoft Authenticator… any of them.') }}</span>
                    <span class="qr" x-data="bookletQr(@js($uri))" x-html="svg" role="img" aria-label="{{ __('QR code for your authenticator app') }}"></span>
                    <details class="setup-2fa__manual">
                        <summary>{{ __('Can’t scan? Type this key instead') }}</summary>
                        <code>{{ trim(chunk_split($setupSecret, 4, ' ')) }}</code>
                    </details>
                </li>
                <li>
                    <strong>{{ __('Type the 6-digit code it shows') }}</strong>
                    <form wire:submit="confirm" class="field" style="max-width: 260px">
                        <label for="setup-code" class="sr-only">{{ __('Code from your app') }}</label>
                        <input id="setup-code" type="text" wire:model="setupCode" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456" class="code-input" autofocus>
                        @error('setupCode') <p class="field__error">{{ $message }}</p> @enderror
                        <div style="display: flex; gap: 10px; margin-top: 6px">
                            <button type="submit" class="btn btn--ink">{{ __('Turn it on') }}</button>
                            <button type="button" class="btn btn--quiet" wire:click="cancelSetup">{{ __('Not now') }}</button>
                        </div>
                    </form>
                </li>
            </ol>
        @elseif ($on)
            <p class="security__status is-on"><span aria-hidden="true">●</span> {{ __('On since :when. Signing in asks for a code from your app.', ['when' => $since?->translatedFormat('j F Y')]) }}</p>
            <p class="field__hint">{{ trans_choice(':count recovery code left.|:count recovery codes left.', $codesLeft) }}</p>

            @if ($asking && $asking !== 'passkey')
                <form wire:submit="answer" class="field" style="max-width: 320px">
                    <x-admin.password id="confirm-password" model="password" autocomplete="current-password" autofocus class="pw-field--bare"
                                      :label="$asking === 'off' ? __('Your password, to turn it off') : __('Your password, for new codes')" />
                    <div style="display: flex; gap: 10px; margin-top: 6px">
                        <button type="submit" @class(['btn', 'btn--danger' => $asking === 'off', 'btn--ink' => $asking !== 'off'])>{{ $asking === 'off' ? __('Turn off') : __('Make new codes') }}</button>
                        <button type="button" class="btn btn--quiet" wire:click="ask('')">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @else
                <div style="display: flex; gap: 16px; flex-wrap: wrap">
                    <button type="button" class="link-button" wire:click="ask('codes')">{{ __('New recovery codes') }}</button>
                    <button type="button" class="link-button bin-list__forget" wire:click="ask('off')">{{ __('Turn off') }}</button>
                </div>
            @endif
        @else
            <p style="font-size: 15px; color: var(--muted)">{!! __('Sign in with your password :and a code from an app on your phone, so a leaked password alone can’t open the book.', ['and' => '<em>'.e(__('and')).'</em>']) !!}</p>
            <button type="button" class="btn" style="align-self: flex-start" wire:click="start">{{ __('Turn on two-step sign-in') }}</button>
        @endif
    </div>

    {{-- Where you're signed in --}}
    <div class="security__block">
        <h2 class="heading heading--sub">{{ __('Where you’re signed in') }}</h2>
        @unless ($devicesAvailable)
            <p class="field__hint">This needs sessions kept in the database (SESSION_DRIVER=database in .env).</p>
        @else
            <ul class="devices">
                @foreach ($devices as $d)
                    <li wire:key="device-{{ md5($d['id']) }}">
                        <span class="devices__icon" aria-hidden="true"><x-admin.icon :name="['phone' => 'phone', 'tablet' => 'tablet', 'computer' => 'laptop'][$d['kind']]" size="18" /></span>
                        <span class="devices__text">
                            <strong>{{ __(':browser on :os', ['browser' => $d['browser'], 'os' => $d['os']]) }}</strong>
                            <span>
                                @if ($d['current']) <span class="sticker sticker--mint" style="--tilt: 0deg; font-size: 11px; padding: 1px 7px">{{ __('This device') }}</span> @endif
                                {{ $d['ip'] }} · {{ $d['current'] ? __('now') : __('active :when', ['when' => $d['last']->diffForHumans()]) }}
                            </span>
                        </span>
                        @unless ($d['current'])
                            <button type="button" class="link-button bin-list__forget" wire:click="signOut(@js($d['id']))">{{ __('Sign out') }}</button>
                        @endunless
                    </li>
                @endforeach
            </ul>
            @if ($devices->where('current', false)->isNotEmpty())
                <button type="button" class="btn btn--sm" style="align-self: flex-start" wire:click="signOutOthers"
                        wire:confirm="{{ __('Sign out on every other device? You’ll stay signed in here.') }}">{{ __('Sign out everywhere else') }}</button>
            @endif
            <p class="field__hint">{{ __('See something you don’t recognise? Sign it out, then change your password.') }}</p>
        @endunless
    </div>
</div>
