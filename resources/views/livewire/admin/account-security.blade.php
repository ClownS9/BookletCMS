<div class="security" id="security">
    {{-- Two-step sign-in --}}
    <div class="security__block">
        <h2 class="heading" style="display: flex; align-items: center; gap: 10px"><x-admin.icon name="lock" size="22" /> Two-step sign-in</h2>

        @if ($freshCodes)
            {{-- Recovery codes: shown once --}}
            <div class="recovery">
                <p><strong>Your recovery codes.</strong> If your phone is lost, each of these lets you in once. Keep them somewhere safe, like a password manager. You won’t see them again.</p>
                <ol class="recovery__codes">
                    @foreach ($freshCodes as $c) <li><code>{{ $c }}</code></li> @endforeach
                </ol>
                <div style="display: flex; gap: 10px; flex-wrap: wrap"
                     x-data="{ copied: false, text: @js(implode("\n", $freshCodes)) }">
                    <button type="button" class="btn btn--sm" x-on:click="navigator.clipboard.writeText(text); copied = true; setTimeout(() => copied = false, 1500)">
                        <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                    </button>
                    <a class="btn btn--sm" :href="'data:text/plain;charset=utf-8,' + encodeURIComponent('Recovery codes for {{ addslashes(app(\App\Booklet\Site::class)->name()) }} ({{ request()->user()->email }})\n\n' + text)" download="booklet-recovery-codes.txt">Download</a>
                    <button type="button" class="btn btn--sm btn--ink" wire:click="doneWithCodes">I’ve saved them</button>
                </div>
            </div>
        @elseif ($setupSecret)
            {{-- Setting it up --}}
            <ol class="setup-2fa">
                <li>
                    <strong>Scan this with your authenticator app</strong>
                    <span>Google Authenticator, 1Password, Authy, Microsoft Authenticator… any of them.</span>
                    <span class="qr" x-data="bookletQr(@js($uri))" x-html="svg" role="img" aria-label="QR code for your authenticator app"></span>
                    <details class="setup-2fa__manual">
                        <summary>Can’t scan? Type this key instead</summary>
                        <code>{{ trim(chunk_split($setupSecret, 4, ' ')) }}</code>
                    </details>
                </li>
                <li>
                    <strong>Type the 6-digit code it shows</strong>
                    <form wire:submit="confirm" class="field" style="max-width: 260px">
                        <label for="setup-code" class="sr-only">Code from your app</label>
                        <input id="setup-code" type="text" wire:model="setupCode" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456" class="code-input" autofocus>
                        @error('setupCode') <p class="field__error">{{ $message }}</p> @enderror
                        <div style="display: flex; gap: 10px; margin-top: 6px">
                            <button type="submit" class="btn btn--ink">Turn it on</button>
                            <button type="button" class="btn btn--quiet" wire:click="cancelSetup">Not now</button>
                        </div>
                    </form>
                </li>
            </ol>
        @elseif ($on)
            <p class="security__status is-on"><span aria-hidden="true">●</span> On since {{ $since?->format('j F Y') }}. Signing in asks for a code from your app.</p>
            <p class="field__hint">{{ $codesLeft }} {{ str('recovery code')->plural($codesLeft) }} left.</p>

            @if ($asking)
                <form wire:submit="answer" class="field" style="max-width: 320px">
                    <x-admin.password id="confirm-password" model="password" autocomplete="current-password" autofocus class="pw-field--bare"
                                      :label="$asking === 'off' ? 'Your password, to turn it off' : 'Your password, for new codes'" />
                    <div style="display: flex; gap: 10px; margin-top: 6px">
                        <button type="submit" @class(['btn', 'btn--danger' => $asking === 'off', 'btn--ink' => $asking !== 'off'])>{{ $asking === 'off' ? 'Turn off' : 'Make new codes' }}</button>
                        <button type="button" class="btn btn--quiet" wire:click="ask('')">Cancel</button>
                    </div>
                </form>
            @else
                <div style="display: flex; gap: 16px; flex-wrap: wrap">
                    <button type="button" class="link-button" wire:click="ask('codes')">New recovery codes</button>
                    <button type="button" class="link-button bin-list__forget" wire:click="ask('off')">Turn off</button>
                </div>
            @endif
        @else
            <p style="font-size: 15px; color: var(--muted)">Sign in with your password <em>and</em> a code from an app on your phone, so a leaked password alone can’t open the book.</p>
            <button type="button" class="btn" style="align-self: flex-start" wire:click="start">Turn on two-step sign-in</button>
        @endif
    </div>

    {{-- Where you're signed in --}}
    <div class="security__block">
        <h2 class="heading heading--sub">Where you’re signed in</h2>
        @unless ($devicesAvailable)
            <p class="field__hint">This needs sessions kept in the database (SESSION_DRIVER=database in .env).</p>
        @else
            <ul class="devices">
                @foreach ($devices as $d)
                    <li wire:key="device-{{ md5($d['id']) }}">
                        <span class="devices__icon" aria-hidden="true"><x-admin.icon :name="['phone' => 'phone', 'tablet' => 'tablet', 'computer' => 'laptop'][$d['kind']]" size="18" /></span>
                        <span class="devices__text">
                            <strong>{{ $d['browser'] }} on {{ $d['os'] }}</strong>
                            <span>
                                @if ($d['current']) <span class="sticker sticker--mint" style="--tilt: 0deg; font-size: 11px; padding: 1px 7px">This device</span> @endif
                                {{ $d['ip'] }} · {{ $d['current'] ? 'now' : 'active '.$d['last']->diffForHumans() }}
                            </span>
                        </span>
                        @unless ($d['current'])
                            <button type="button" class="link-button bin-list__forget" wire:click="signOut(@js($d['id']))">Sign out</button>
                        @endunless
                    </li>
                @endforeach
            </ul>
            @if ($devices->where('current', false)->isNotEmpty())
                <button type="button" class="btn btn--sm" style="align-self: flex-start" wire:click="signOutOthers"
                        wire:confirm="Sign out on every other device? You’ll stay signed in here.">Sign out everywhere else</button>
            @endif
            <p class="field__hint">See something you don’t recognise? Sign it out, then change your password.</p>
        @endunless
    </div>
</div>
