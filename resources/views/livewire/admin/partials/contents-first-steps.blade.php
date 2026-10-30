@if ($checklist !== [])
    @php
        $todo = array_values(array_filter($checklist, fn ($i) => ! $i['done']));
        $doneItems = array_values(array_filter($checklist, fn ($i) => $i['done']));
    @endphp
    <section class="first-steps" aria-labelledby="first-steps-title" x-data="{ showDone: false }">
        <div class="first-steps__head">
            <h2 id="first-steps-title" class="eyebrow">{{ __('First steps') }}</h2>
            <span class="first-steps__count">{{ __(':done of :all', ['done' => count($doneItems), 'all' => count($checklist)]) }}</span>
            <button type="button" class="link-button first-steps__away" wire:click="hideChecklist">{{ __('Put away') }}</button>
        </div>
        <div class="first-steps__bar" role="progressbar" aria-label="{{ __('First steps done') }}" aria-valuemin="0" aria-valuemax="{{ count($checklist) }}" aria-valuenow="{{ count($doneItems) }}">
            <span style="width: {{ round(count($doneItems) / max(1, count($checklist)) * 100) }}%"></span>
        </div>

        @if ($todo === [])
            <p class="first-steps__all"><span class="sticker sticker--mint" style="--tilt: -4deg">{{ __('All set') }}</span> {{ __('Everything’s in place. The rest is writing.') }}</p>
        @else
            <ul class="first-steps__list">
                @foreach ($todo as $item)
                    <li wire:key="step-{{ $item['key'] }}">
                        <span class="first-steps__box" aria-hidden="true"></span>
                        <span style="min-width: 0">
                            @if ($item['href'])
                                <a href="{{ $item['href'] }}" @unless (str_contains($item['href'], '#')) wire:navigate @endunless>{{ __($item['label']) }}</a>
                            @else
                                <span>{{ __($item['label']) }}</span>
                            @endif
                            <small>{{ __($item['hint']) }}</small>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($doneItems !== [] && $todo !== [])
            <button type="button" class="link-button first-steps__toggle" x-on:click="showDone = ! showDone" :aria-expanded="showDone">
                <span x-text="showDone ? @js(__('Hide what’s done')) : @js(__('Show what’s done (:count)', ['count' => count($doneItems)]))">{{ __('Show what’s done (:count)', ['count' => count($doneItems)]) }}</span>
            </button>
            <ul class="first-steps__list first-steps__list--done" x-show="showDone" x-cloak>
                @foreach ($doneItems as $item)
                    <li wire:key="done-{{ $item['key'] }}"><span class="first-steps__box first-steps__box--done" aria-hidden="true"></span><span><span class="sr-only">{{ __('Done:') }} </span>{{ __($item['label']) }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
