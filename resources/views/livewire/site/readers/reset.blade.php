<div class="reader-card">
    <h1 class="reader-card__title">{{ __('A new password') }}</h1>
    <form wire:submit="save" class="reader-form" novalidate>
        <div class="field">
            <label for="reset-email">{{ __('Email address') }}</label>
            <input id="reset-email" type="email" wire:model="email" autocomplete="username" maxlength="190" required>
            @error('email') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="reset-password">{{ __('New password') }}</label>
            <input id="reset-password" type="password" wire:model="password" autocomplete="new-password" maxlength="200" required autofocus aria-describedby="reset-password-hint">
            <p id="reset-password-hint" class="reader-form__hint">{{ __('At least 10 characters. A few unrelated words work well.') }}</p>
            @error('password') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="button button--ink" wire:loading.attr="disabled">{{ __('Save and sign in') }}</button>
    </form>
</div>
