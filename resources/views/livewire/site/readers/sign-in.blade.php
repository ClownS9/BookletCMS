@php $words = \App\Support\Readers::settings(); @endphp
<div class="reader-card">
    <h1 class="reader-card__title">{{ $words['signin_title'] !== '' ? $words['signin_title'] : __('Sign in to :site', ['site' => app(\App\Booklet\Site::class)->name()]) }}</h1>
    @if ($back)
        <p class="reader-card__lede">{{ __('Sign in to keep reading.') }}</p>
    @endif

    <form wire:submit="signIn" class="reader-form" novalidate>
        <div class="field">
            <label for="reader-email">{{ __('Email address') }}</label>
            <input id="reader-email" type="email" wire:model="email" autocomplete="username" maxlength="190" required autofocus>
            @error('email') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="reader-password">{{ __('Password') }}</label>
            <input id="reader-password" type="password" wire:model="password" autocomplete="current-password" maxlength="200" required>
            @error('password') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>
        <label class="reader-form__tick"><input type="checkbox" wire:model="remember"> {{ __('Keep me signed in on this device') }}</label>
        <x-guard form="reader-sign-in" />
        <button type="submit" class="button button--ink" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="signIn">{{ __('Sign in') }}</span>
            <span wire:loading wire:target="signIn">{{ __('One moment…') }}</span>
        </button>
    </form>

    <p class="reader-card__small">
        <a href="{{ route('readers.forgot') }}">{{ __('Forgotten your password?') }}</a>
        @if ($canJoin) · {{ __('New here?') }} <a href="{{ route('readers.join') }}">{{ __('Make an account') }}</a> @endif
    </p>
</div>
