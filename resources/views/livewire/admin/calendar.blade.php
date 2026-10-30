<div style="display: contents">
    {{-- Left page: key, drafts, the chosen day --}}
    <section class="page page--left page--stack" style="flex-basis: 300px">
        <div>
            <a href="{{ route('admin.entries', 'posts') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Posts') }}</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Calendar') }}</h1>
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px; font-size: 14px">
            <span style="display: flex; align-items: center; gap: 10px"><span class="dot" style="--dot: var(--mint); --tilt: 0deg"></span>{{ __('Published') }}</span>
            <span style="display: flex; align-items: center; gap: 10px"><span class="dot" style="--dot: var(--sky); --tilt: 0deg"></span>{{ __('Scheduled') }}</span>
            <span style="display: flex; align-items: center; gap: 10px"><span class="dot" style="--dot: var(--sun); --tilt: 0deg"></span>{{ __('Draft with a date') }}</span>
            <span style="display: flex; align-items: center; gap: 10px"><span class="dot" style="--dot: var(--coral); --tilt: 0deg"></span>{{ __('Comes down') }}</span>
        </div>

        <div style="padding-top: 20px; border-top: 1px solid var(--rule)">
            <h2 class="eyebrow section-label">{{ __($chosen['label']) }}@if ($chosen['today']) · {{ __('today') }} @endif</h2>
            @forelse ($chosen['entries'] as $item)
                <a href="{{ $item['href'] }}" class="row" style="padding: 10px; gap: 10px" wire:navigate>
                    <span class="dot" style="--dot: {{ $tones[$item['status']] }}; --tilt: 0deg"></span>
                    <span class="row__title" style="font-size: 17px">{{ $item['title'] }}</span>
                    <span class="row__meta">{{ $item['time'] ?? '' }} · {{ __($item['label'] ?? ucfirst($item['status'])) }}</span>
                </a>
            @empty
                <p class="serif muted" style="font-style: italic; font-size: 17px">{{ __('A blank page.') }} <a href="{{ route('admin.entries.create', ['posts', 'date' => $chosen['date']]) }}" wire:navigate>{{ __('Plan a post for this day →') }}</a></p>
            @endforelse
        </div>

        @if ($drafts->isNotEmpty())
            <div style="padding-top: 20px; border-top: 1px solid var(--rule)">
                <h2 class="eyebrow section-label">{{ __('Drafts on the desk') }}</h2>
                <p class="field__hint" style="margin-bottom: 10px">{{ __('Drag one onto a day to plan it.') }}</p>
                @foreach ($drafts as $i => $draft)
                    <a href="{{ route('admin.entries.edit', [$draft->collectionHandle(), $draft->id()]) }}" class="draft-note" style="--tilt: {{ $i % 2 ? '1deg' : '-1deg' }}" wire:navigate
                       draggable="true" x-data x-on:dragstart="$event.dataTransfer.setData('text/booklet-entry', @js($draft->id())); $event.dataTransfer.effectAllowed = 'move'">
                        {{ $draft->title() ?: 'Untitled' }}
                        @if ($draft->date()) <span class="muted" style="font-size: 12px">· {{ $draft->date()->translatedFormat('j M') }}</span> @endif
                    </a>
                @endforeach
            </div>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the month --}}
    <section class="page page--right" style="--ribbon: var(--sun)">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap">
            <h2 class="display" style="margin-right: auto; font-size: 36px">{{ $first->translatedFormat('F') }} <span class="muted">{{ $first->format('Y') }}</span></h2>
            <button type="button" class="icon-btn" style="border: 1px solid var(--rule); border-radius: 50%; width: 44px; height: 44px" wire:click="previousMonth" aria-label="{{ __('Previous month') }}">←</button>
            <button type="button" class="btn" wire:click="today">{{ __('Today') }}</button>
            <button type="button" class="icon-btn" style="border: 1px solid var(--rule); border-radius: 50%; width: 44px; height: 44px" wire:click="nextMonth" aria-label="{{ __('Next month') }}">→</button>
        </div>

        <div class="month" aria-hidden="true" style="margin-top: 22px">
            @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $weekday)
                <span class="month__weekday">{{ $weekday }}</span>
            @endforeach
        </div>
        <div class="month" role="group" aria-label="{{ __(':month, pick a day', ['month' => $first->translatedFormat('F Y')]) }}" style="margin-top: 8px">
            @foreach ($days as $d)
                <button type="button" wire:key="day-{{ $d['date'] }}" wire:click="pickDay('{{ $d['date'] }}')"
                        x-data="{ over: false }" :class="{ 'day--over': over }"
                        x-on:dragover.prevent="over = true" x-on:dragleave="over = false"
                        x-on:drop.prevent="over = false; const id = $event.dataTransfer.getData('text/booklet-entry'); if (id) $wire.planDraft(id, '{{ $d['date'] }}')"
                        aria-pressed="{{ $chosen['date'] === $d['date'] ? 'true' : 'false' }}"
                        aria-label="{{ __($d['label']) }}{{ count($d['entries']) ? ', '.trans_choice(':count item|:count items', count($d['entries'])) : '' }}"
                        @class(['day', 'day--outside' => ! $d['inMonth']])>
                    <span @class(['day__n', 'day__n--today' => $d['today']])>{{ $d['n'] }}</span>
                    @foreach (array_slice($d['entries'], 0, 3) as $k => $item)
                        <span class="day__pill" style="background: {{ $tones[$item['status']] }}; --tilt: {{ $k % 2 ? '1deg' : '-1deg' }}">{{ $item['title'] }}</span>
                    @endforeach
                    @if (count($d['entries']) > 3)
                        <span class="day__more">{{ __('+:count more', ['count' => count($d['entries']) - 3]) }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <x-admin.folio side="right" />
    </section>
</div>
