<div>
    @if ($open)
        <section @class(['subscribe', 'subscribe--'.$place]) aria-labelledby="subscribe-{{ $place }}">
            <div class="subscribe__text">
                <h2 id="subscribe-{{ $place }}" class="subscribe__title">{{ __('New posts by email') }}</h2>
                <p class="subscribe__lede">{{ __('One email when something new goes up. Nothing else, and you can leave in one click.') }}</p>
            </div>

            @if ($done === 'already')
                <p class="subscribe__done" role="status">{{ __('You’re already on the list. Thank you!') }}</p>
            @elseif ($done)
                <p class="subscribe__done" role="status">{{ __('Nearly there. Check your inbox for an email from us, and press the button in it to confirm.') }}</p>
            @else
                <form wire:submit="subscribe" class="subscribe__form" novalidate>
                    <label for="subscribe-email-{{ $place }}" class="sr-only">{{ __('Your email address') }}</label>
                    <input id="subscribe-email-{{ $place }}" type="email" wire:model="email" placeholder="you@example.com" autocomplete="email" maxlength="190" required>
                    <div class="leave-note__honey" aria-hidden="true">
                        <label for="subscribe-website-{{ $place }}">{{ __('Website') }}</label>
                        <input id="subscribe-website-{{ $place }}" type="text" wire:model="website" tabindex="-1" autocomplete="off">
                    </div>
                    <button type="submit" class="button button--ink" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="subscribe">{{ __('Send me new posts') }}</span>
                        <span wire:loading wire:target="subscribe">{{ __('One moment…') }}</span>
                    </button>
                </form>
                @error('email') <p class="field__error">{{ $message }}</p> @enderror
                <x-guard form="subscribe" />
            @endif
        </section>
    @endif
</div>
