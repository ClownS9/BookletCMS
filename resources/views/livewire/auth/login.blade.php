<main class="cover">
    <div class="cover__spine" aria-hidden="true"></div>
    <div class="cover__ribbon" aria-hidden="true"></div>

    <div class="cover__title">
        <x-admin.logo size="40" />
        <h1>{{ __('booklet') }}</h1>
        <p>{{ app(\App\Booklet\Site::class)->name() }}</p>
    </div>

    @if ($mode === 'signin')
        <form class="cover__plate" wire:submit="login" wire:key="signin">
            <span class="sticker sticker--sun cover__sticker" aria-hidden="true">{{ __('Welcome back') }}</span>
            <h2>{{ __('Open your book') }}</h2>

            <div class="field">
                <label for="email">{{ __('Email') }}</label>
                <input id="email" type="email" wire:model="email" autocomplete="email" autofocus>
                @error('email') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <x-admin.password id="password" model="password" label="{{ __('Password') }}" autocomplete="current-password" />

            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
                <label class="check">
                    <input type="checkbox" wire:model="remember"> {{ __('Keep me signed in') }}
                </label>
                <button type="button" class="link-button" style="font-size: 14px; color: var(--muted)" wire:click="showForgot">{{ __('Forgot password?') }}</button>
            </div>

            <x-guard form="login" />

            <button type="submit" class="btn btn--ink btn--wide">
                <span wire:loading.remove wire:target="login">{{ __('Sign in') }}</span>
                <span wire:loading wire:target="login">{{ __('Opening…') }}</span>
            </button>

            <div class="cover__passkey" x-data="bookletPasskey('signin')" x-show="available" x-cloak>
                <span class="cover__or" aria-hidden="true">{{ __('or') }}</span>
                <button type="button" class="btn btn--wide" x-on:click="go()" :disabled="busy">
                    <x-admin.icon name="key" size="18" />
                    <span x-show="!busy">{{ __('Sign in with a passkey') }}</span><span x-show="busy">{{ __('Waiting for your device…') }}</span>
                </button>
            </div>
            @error('passkey') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </form>
    @elseif ($mode === 'code')
        {{-- Two-step sign-in: the code from their authenticator app --}}
        <form class="cover__plate" wire:submit="verifyCode" wire:key="code-{{ $usingRecovery ? 'recovery' : 'app' }}">
            <span class="sticker sticker--mint cover__sticker" aria-hidden="true">{{ __('One more step') }}</span>
            <h2>{{ $usingRecovery ? __('Use a recovery code') : __('Enter your code') }}</h2>
            <p style="color: var(--muted); font-size: 15px">
                {{ $usingRecovery
                    ? 'One of the codes you saved when you turned on two-step sign-in. Each works once.'
                    : 'Open your authenticator app and type the 6-digit code for this book.' }}
            </p>

            <div class="field">
                <label for="two-factor-code">{{ $usingRecovery ? __('Recovery code') : __('Code') }}</label>
                @if ($usingRecovery)
                    <input id="two-factor-code" type="text" wire:model="code" autocomplete="off" spellcheck="false" placeholder="{{ __('abcd-efgh') }}" autofocus>
                @else
                    <input id="two-factor-code" type="text" wire:model="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                           autocomplete="one-time-code" placeholder="123 456" class="code-input" autofocus
                           x-data x-on:input="$el.value.replace(/\D/g, '').length === 6 && $nextTick(() => $wire.verifyCode())">
                @endif
                @error('code') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn--ink btn--wide">
                <span wire:loading.remove wire:target="verifyCode">{{ __('Open the book') }}</span>
                <span wire:loading wire:target="verifyCode">{{ __('Checking…') }}</span>
            </button>
            <div style="display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap">
                <button type="button" class="link-button" style="font-size: 14px; color: var(--muted)" wire:click="cancelCode">{{ __('← Back') }}</button>
                <button type="button" class="link-button" style="font-size: 14px; color: var(--muted)" wire:click="toggleRecovery">
                    {{ $usingRecovery ? __('Use my app instead') : __('Lost your phone? Use a recovery code') }}
                </button>
            </div>
        </form>
    @else
        <form class="cover__plate" wire:submit="sendResetLink" wire:key="forgot">
            <span class="sticker sticker--sky cover__sticker" aria-hidden="true">{{ __('Bookmark') }}</span>
            <h2>{{ __('Lost your place?') }}</h2>
            <p style="color: var(--muted); font-size: 15px">{{ __('Tell us your email and we’ll send a link to set a new password.') }}</p>

            <div class="field">
                <label for="reset-email">{{ __('Email') }}</label>
                <input id="reset-email" type="email" wire:model="resetEmail" autocomplete="email" autofocus>
                @error('resetEmail') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            @if ($sent)
                <p class="notice" role="status">{{ __('If that email belongs to someone in this book, the link is on its way. Check your inbox.') }}</p>
            @endif

            <x-guard form="reset" />

            <button type="submit" class="btn btn--ink btn--wide">
                <span wire:loading.remove wire:target="sendResetLink">{{ __('Send reset link') }}</span>
                <span wire:loading wire:target="sendResetLink">{{ __('Sending…') }}</span>
            </button>
            <button type="button" class="link-button" style="align-self: center; font-size: 14px; color: var(--muted)" wire:click="showSignIn">{{ __('← Back to sign in') }}</button>
        </form>
    @endif
</main>
