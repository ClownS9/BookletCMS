<main class="cover">
    <div class="cover__spine" aria-hidden="true"></div>
    <div class="cover__ribbon" aria-hidden="true"></div>

    <div class="cover__title">
        <x-admin.logo size="40" />
        <h1>booklet</h1>
        <p>{{ app(\App\Booklet\Site::class)->name() }}</p>
    </div>

    <form class="cover__plate" wire:submit="resetPassword">
        <span class="sticker sticker--mint cover__sticker" aria-hidden="true">New page</span>
        <h2>Choose a new password</h2>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" wire:model="email" autocomplete="email">
            @error('email') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        <x-admin.password id="password" model="password" label="New password" autocomplete="new-password" meter autofocus
                          :personal-from="['email']" hint="At least 10 characters." />
        <x-admin.password id="password-confirmation" model="password_confirmation" label="The same again" autocomplete="new-password" match="password" />

        <button type="submit" class="btn btn--ink btn--wide">Save and sign in</button>
        <a href="{{ route('login') }}" style="align-self: center; font-size: 14px; color: var(--muted)">← Back to sign in</a>
    </form>
</main>
