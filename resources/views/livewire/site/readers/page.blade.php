@php $site = app(\App\Booklet\Site::class); @endphp
<div class="reader-page">
    <header class="reader-page__head">
        <div>
            <p class="reader-page__eyebrow">{{ __('Your account') }}</p>
            <h1 class="reader-card__title">{{ __('Hello, :name', ['name' => $reader->firstName()]) }}</h1>
        </div>
        <form method="POST" action="{{ route('readers.sign-out') }}">
            @csrf
            <button type="submit" class="button">{{ __('Sign out') }}</button>
        </form>
    </header>

    @if ($notice) <p class="reader-page__notice" role="status">{{ $notice }}</p> @endif
    @if (session('readers.confirmed') && $reader->isActive()) <p class="reader-page__notice" role="status">{{ __('Your address is confirmed. Welcome!') }}</p> @endif
    @if (session('readers.joined')) <p class="reader-page__notice" role="status">{{ __('You’re in :group.', ['group' => session('readers.joined')]) }}</p> @endif

    @if (! $reader->confirmed())
        <section class="reader-card reader-card--note">
            <h2 class="reader-card__subtitle">{{ __('Confirm your email address') }}</h2>
            <p>{{ __('We sent an email to :email. Press the button in it, and you’re all set.', ['email' => $reader->email]) }}</p>
            <button type="button" class="button" wire:click="sendConfirmAgain">{{ __('Send it again') }}</button>
        </section>
    @elseif ($reader->state === 'waiting')
        <section class="reader-card reader-card--note">
            <h2 class="reader-card__subtitle">{{ __('Waiting to be let in') }}</h2>
            <p>{{ __('Your address is confirmed. Someone at :site lets new readers in by hand, and we’ll email you when they have.', ['site' => $site->name()]) }}</p>
        </section>
    @endif

    @if ($reader->isActive() && $groups->isNotEmpty())
        <section class="reader-card">
            <h2 class="reader-card__subtitle">{{ __('Groups') }}</h2>
            <ul class="reader-groups">
                @foreach ($groups as $row)
                    @php $g = $row['group']; @endphp
                    <li wire:key="g-{{ $g->id }}">
                        <span>
                            <strong>{{ $g->name }}</strong>
                            @if ($g->about) <span class="reader-groups__about">{{ $g->about }}</span> @endif
                        </span>
                        @if ($row['state'] === 'in')
                            <span class="reader-groups__state">{{ __('You’re in') }}</span>
                            <button type="button" class="reader-link" wire:click="leaveGroup({{ $g->id }})" wire:confirm="{{ __('Leave :group?', ['group' => $g->name]) }}">{{ __('Leave') }}</button>
                        @elseif ($row['state'] === 'asked')
                            <span class="reader-groups__state">{{ __('Asked') }}</span>
                            <button type="button" class="reader-link" wire:click="leaveGroup({{ $g->id }})">{{ __('Take it back') }}</button>
                        @else
                            <button type="button" class="button" wire:click="joinGroup({{ $g->id }})">{{ $g->joining === 'open' ? __('Join') : __('Ask to join') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="reader-card">
        <h2 class="reader-card__subtitle">{{ __('Your details') }}</h2>
        <form wire:submit="saveDetails" class="reader-form" novalidate>
            <div class="field">
                <label for="me-name">{{ __('Your name') }}</label>
                <input id="me-name" type="text" wire:model="name" autocomplete="name" maxlength="120" required>
                @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="me-email">{{ __('Email address') }}</label>
                <input id="me-email" type="email" value="{{ $reader->email }}" readonly aria-describedby="me-email-hint">
                <p id="me-email-hint" class="reader-form__hint">{{ __('To change it, write to us from the new address.') }}</p>
            </div>
            @foreach ($questions as $q)
                @php $id = 'me-q-'.$loop->index; $model = 'answers.'.$q['key']; @endphp
                @if ($q['type'] === 'tick')
                    <label class="reader-form__tick" for="{{ $id }}"><input id="{{ $id }}" type="checkbox" wire:model="{{ $model }}"> {{ $q['label'] }}</label>
                @else
                    <div class="field">
                        <label for="{{ $id }}">{{ $q['label'] }}</label>
                        @if ($q['type'] === 'long')
                            <textarea id="{{ $id }}" wire:model="{{ $model }}" rows="3" maxlength="2000"></textarea>
                        @elseif ($q['type'] === 'choice')
                            <select id="{{ $id }}" wire:model="{{ $model }}">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach ($q['options'] as $option) <option value="{{ $option }}">{{ $option }}</option> @endforeach
                            </select>
                        @else
                            <input id="{{ $id }}" type="text" wire:model="{{ $model }}" maxlength="200">
                        @endif
                    </div>
                @endif
                @error($model) <p class="field__error" role="alert">{{ $message }}</p> @enderror
            @endforeach
            <button type="submit" class="button button--ink">{{ __('Save') }}</button>
        </form>
    </section>

    @if ($newsletter && $reader->confirmed())
        <section class="reader-card">
            <h2 class="reader-card__subtitle">{{ __('New posts by email') }}</h2>
            <label class="reader-form__tick">
                <input type="checkbox" wire:click="toggleNewsletter" @checked($subscribed)>
                {{ __('Send new posts to :email', ['email' => $reader->email]) }}
            </label>
        </section>
    @endif

    <section class="reader-card">
        <h2 class="reader-card__subtitle">{{ __('Password') }}</h2>
        <form wire:submit="changePassword" class="reader-form" novalidate>
            <input type="email" value="{{ $reader->email }}" autocomplete="username" hidden>
            <div class="field">
                <label for="me-current">{{ __('Your password now') }}</label>
                <input id="me-current" type="password" wire:model="currentPassword" autocomplete="current-password" maxlength="200">
                @error('currentPassword') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="me-new">{{ __('New password') }}</label>
                <input id="me-new" type="password" wire:model="newPassword" autocomplete="new-password" maxlength="200">
                @error('newPassword') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="button">{{ __('Change password') }}</button>
        </form>
    </section>

    <details class="reader-card reader-leave">
        <summary>{{ __('Delete my account') }}</summary>
        <p>{{ __('Your account and your groups are gone at once. Comments you left stay, under the name you gave.') }}</p>
        <form wire:submit="leave" class="reader-form" novalidate>
            <div class="field">
                <label for="me-leave">{{ __('Your password, to be sure') }}</label>
                <input id="me-leave" type="password" wire:model="leavePassword" autocomplete="current-password" maxlength="200">
                @error('leavePassword') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="button reader-leave__button">{{ __('Delete my account') }}</button>
        </form>
    </details>
</div>
