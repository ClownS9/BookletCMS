<div class="reader-card">
    <h1 class="reader-card__title">{{ __('Forgotten your password?') }}</h1>
    @if ($sent)
        <p class="reader-card__lede" role="status">{{ __('If :email has an account here, an email with a link to make a new password is on its way. The link works for an hour.', ['email' => $email]) }}</p>
        <p class="reader-card__small"><a href="{{ route('readers.sign-in') }}">{{ __('Back to signing in') }}</a></p>
    @else
        <p class="reader-card__lede">{{ __('Type your email address and we’ll send a link to make a new one.') }}</p>
        <form wire:submit="send" class="reader-form" novalidate>
            <div class="field">
                <label for="forgot-email">{{ __('Email address') }}</label>
                <input id="forgot-email" type="email" wire:model="email" autocomplete="email" maxlength="190" required autofocus>
                @error('email') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="button button--ink" wire:loading.attr="disabled">{{ __('Send the link') }}</button>
        </form>
        <p class="reader-card__small"><a href="{{ route('readers.sign-in') }}">{{ __('Back to signing in') }}</a></p>
    @endif
</div>
