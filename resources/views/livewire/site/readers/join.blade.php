@php $words = \App\Support\Readers::settings(); $site = app(\App\Booklet\Site::class); @endphp
<div class="reader-card">
    @if (! $this->open())
        <h1 class="reader-card__title">{{ __('Joining is by invitation') }}</h1>
        <p class="reader-card__lede">{{ __('Accounts here are made by invitation. If you have one, use the link in its email.') }}</p>
        <p class="reader-card__small"><a href="{{ route('readers.sign-in') }}">{{ __('Already have an account? Sign in') }}</a></p>
    @elseif ($done)
        <span class="sticker" style="--tilt: -3deg">{{ __('Nearly there') }}</span>
        <h1 class="reader-card__title">{{ __('Check your inbox') }}</h1>
        <p class="reader-card__lede">{{ __('We’ve sent an email to :email. Press the button in it to confirm your address.', ['email' => $email]) }}</p>
        @if ($joining === 'approval' && ! $groupName)
            <p class="reader-card__lede">{{ __('Then someone at :site lets you in, and we’ll email you when they have.', ['site' => $site->name()]) }}</p>
        @endif
        <p class="reader-card__actions"><a href="{{ route('readers.home') }}" class="button">{{ __('Go to your account') }}</a></p>
    @else
        <h1 class="reader-card__title">{{ $words['join_title'] !== '' ? $words['join_title'] : ($groupName ? __('Join :group', ['group' => $groupName]) : __('Join :site', ['site' => $site->name()])) }}</h1>
        @if ($words['join_lede'] !== '')
            <p class="reader-card__lede">{{ $words['join_lede'] }}</p>
        @elseif ($joining === 'approval' && ! $invite && ! $groupName)
            <p class="reader-card__lede">{{ __('Make an account, confirm your email, and someone at :site lets you in.', ['site' => $site->name()]) }}</p>
        @else
            <p class="reader-card__lede">{{ __('An account lets you read what’s for members, and comment without typing your name each time.') }}</p>
        @endif

        <form wire:submit="join" class="reader-form" novalidate>
            <div class="field">
                <label for="join-name">{{ __('Your name') }}</label>
                <input id="join-name" type="text" wire:model="name" autocomplete="name" maxlength="120" required>
                @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="join-email">{{ __('Email address') }}</label>
                <input id="join-email" type="email" wire:model="email" autocomplete="email" maxlength="190" required @if ($invite) readonly @endif>
                @error('email') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="join-password">{{ __('Make a password') }}</label>
                <input id="join-password" type="password" wire:model="password" autocomplete="new-password" maxlength="200" required aria-describedby="join-password-hint">
                <p id="join-password-hint" class="reader-form__hint">{{ __('At least 10 characters. A few unrelated words work well.') }}</p>
                @error('password') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            @foreach ($questions as $q)
                @php $id = 'join-q-'.$loop->index; $model = 'answers.'.$q['key']; @endphp
                @if ($q['type'] === 'tick')
                    <label class="reader-form__tick" for="{{ $id }}"><input id="{{ $id }}" type="checkbox" wire:model="{{ $model }}"> {{ $q['label'] }}</label>
                @else
                    <div class="field">
                        <label for="{{ $id }}">{{ $q['label'] }} @unless ($q['required']) <span class="reader-form__optional">{{ __('(optional)') }}</span> @endunless</label>
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

            <div class="leave-note__honey" aria-hidden="true">
                <label for="join-website">{{ __('Website') }}</label>
                <input id="join-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>
            <x-guard form="join" />
            <button type="submit" class="button button--ink" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="join">{{ __('Make my account') }}</span>
                <span wire:loading wire:target="join">{{ __('One moment…') }}</span>
            </button>
        </form>
        <p class="reader-card__small">{{ __('Already have an account?') }} <a href="{{ route('readers.sign-in') }}">{{ __('Sign in') }}</a></p>
    @endif
</div>
