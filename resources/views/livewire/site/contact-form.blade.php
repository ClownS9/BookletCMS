<section class="letter" aria-labelledby="letter-heading">
    @if ($sent)
        <div class="letter__sent" role="status">
            <span class="letter__stamp" aria-hidden="true"></span>
            <h2 id="letter-heading">{{ __('Your message is on its way') }}</h2>
            <p>{{ __('Thank you for writing. We read every message, and we’ll write back if you asked us something.') }}</p>
            <button type="button" class="button" wire:click="again">{{ __('Write another') }}</button>
        </div>
    @else
        <form wire:submit="send" novalidate>
            <h2 id="letter-heading">{{ __('Write to us') }}</h2>

            @php
                $short = collect($fields)->filter(fn ($f) => $f['type'] !== 'textarea');
                $long = collect($fields)->filter(fn ($f) => $f['type'] === 'textarea');
            @endphp

            @if ($short->isNotEmpty())
                <div class="leave-note__row">
                    @foreach ($short as $handle => $f)
                        <div class="field">
                            <label for="contact-{{ $handle }}">{{ $f['label'] }}@unless ($f['required']) <span class="muted">{{ __('(optional)') }}</span>@endunless</label>
                            <input id="contact-{{ $handle }}" type="{{ $f['input'] }}" wire:model="values.{{ $handle }}" maxlength="190"
                                   @if ($handle === 'name') autocomplete="name" @elseif ($f['input'] === 'email') autocomplete="email" @endif
                                   @if ($f['required']) required aria-required="true" @endif
                                   @error('values.'.$handle) aria-invalid="true" aria-describedby="contact-{{ $handle }}-error" @enderror>
                            @error('values.'.$handle) <p class="field__error" id="contact-{{ $handle }}-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            @endif

            @foreach ($long as $handle => $f)
                <div class="field">
                    <label for="contact-{{ $handle }}">{{ $f['label'] }}@unless ($f['required']) <span class="muted">{{ __('(optional)') }}</span>@endunless</label>
                    <textarea id="contact-{{ $handle }}" rows="6" wire:model="values.{{ $handle }}" maxlength="{{ \App\Support\Inbox::MESSAGE_MAX }}"
                              @if ($f['required']) required aria-required="true" @endif
                              @error('values.'.$handle) aria-invalid="true" aria-describedby="contact-{{ $handle }}-error" @enderror></textarea>
                    @error('values.'.$handle) <p class="field__error" id="contact-{{ $handle }}-error">{{ $message }}</p> @enderror
                </div>
            @endforeach

            <div class="leave-note__honey" aria-hidden="true">
                <label for="contact-website">{{ __('Leave this empty') }}</label>
                <input id="contact-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <p class="letter__small">{{ __('Your email is only used to write back to you.') }}</p>

            <x-guard form="contact" />

            <button type="submit" class="button button--ink" wire:loading.attr="disabled" wire:target="send">
                <span wire:loading.remove wire:target="send">{{ __('Send message') }}</span>
                <span wire:loading wire:target="send">{{ __('Sending…') }}</span>
            </button>
        </form>
    @endif
</section>
