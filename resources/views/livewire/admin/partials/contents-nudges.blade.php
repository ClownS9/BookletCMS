@if ($nudges)
    <h2 class="eyebrow section-label section-label--gap">{{ __('Stacy noticed') }}</h2>
    <ul class="nudges">
        @foreach ($nudges as $nudge)
            <li wire:key="nudge-{{ md5($nudge['key']) }}">
                <span class="nudges__mark" aria-hidden="true"><x-admin.icon name="stacy" size="14" /></span>
                <span class="nudges__text">{{ __($nudge['text']) }}</span>
                <span class="nudges__do">
                    <a href="{{ $nudge['href'] }}" class="btn btn--sm" wire:navigate>{{ __($nudge['label']) }}</a>
                    <button type="button" class="link-button nudges__later" wire:click="notNow(@js($nudge['key']))">{{ __('Not now') }}</button>
                </span>
            </li>
        @endforeach
    </ul>
@endif
