@php
    $statusLabels = ['published' => 'Published', 'scheduled' => 'Scheduled', 'draft' => 'Draft with a date', 'down' => 'Comes down'];
    $monthCounts = collect($days)->where('inMonth', true)->flatMap(fn ($d) => $d['entries'])->countBy('status');
    $weekdays = [__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')];
@endphp
<div style="display: contents">
    {{-- The calendar in the Dashboard layout: the month fills the page as one
         grid, with the chosen day and the drafts waiting for a date beside it. --}}
    <section class="page page--single page--dash" aria-labelledby="calendar-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="calendar-title">{{ __('Calendar') }}</h1>
                    <p>{{ __('What went out, what’s coming, and what comes down, day by day.') }}</p>
                </div>
                <a href="{{ route('admin.entries.create', ['posts', 'date' => $chosen['date']]) }}" class="btn btn--ink" wire:navigate><x-admin.icon name="plus" size="16" /> {{ __('Plan a post') }}</a>
            </div>

            <div class="dcal-layout">
                <section class="dash-card dcal" aria-labelledby="dcal-month">
                    <div class="dcal__bar">
                        <div class="dcal__nav">
                            <button type="button" class="btn btn--sm" wire:click="today">{{ __('Today') }}</button>
                            <button type="button" class="dcal__arrow" wire:click="previousMonth" aria-label="{{ __('Previous month') }}"><span aria-hidden="true">‹</span></button>
                            <button type="button" class="dcal__arrow" wire:click="nextMonth" aria-label="{{ __('Next month') }}"><span aria-hidden="true">›</span></button>
                            <h2 id="dcal-month">{{ $first->translatedFormat('F Y') }}</h2>
                        </div>
                        <ul class="dcal__key" aria-label="{{ __('This month') }}">
                            @foreach ($statusLabels as $status => $label)
                                <li><i style="background: {{ $tones[$status] }}" aria-hidden="true"></i>{{ __($label) }} <b>{{ $monthCounts[$status] ?? 0 }}</b></li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="dcal__weekdays" aria-hidden="true">
                        @foreach ($weekdays as $i => $weekday)
                            <span @class(['is-weekend' => $i >= 5])>{{ $weekday }}</span>
                        @endforeach
                    </div>
                    <div class="dcal__grid" role="group" aria-label="{{ __(':month, pick a day', ['month' => $first->translatedFormat('F Y')]) }}">
                        @foreach ($days as $i => $d)
                            <button type="button" wire:key="dday-{{ $d['date'] }}" wire:click="pickDay('{{ $d['date'] }}')"
                                    x-data="{ over: false }" :class="{ 'is-over': over }"
                                    x-on:dragover.prevent="over = true" x-on:dragleave="over = false"
                                    x-on:drop.prevent="over = false; const id = $event.dataTransfer.getData('text/booklet-entry'); if (id) $wire.planDraft(id, '{{ $d['date'] }}')"
                                    aria-pressed="{{ $chosen['date'] === $d['date'] ? 'true' : 'false' }}"
                                    aria-label="{{ __($d['label']) }}{{ count($d['entries']) ? ', '.trans_choice(':count item|:count items', count($d['entries'])) : '' }}"
                                    @class(['dcal__day', 'is-outside' => ! $d['inMonth'], 'is-weekend' => $i % 7 >= 5, 'is-today' => $d['today']])>
                                <span class="dcal__n">{{ $d['n'] }}</span>
                                <span class="dcal__items">
                                @foreach (array_slice($d['entries'], 0, 3) as $item)
                                    <span class="dcal__item" style="--tone: {{ $tones[$item['status']] }}"><i aria-hidden="true"></i><span>{{ $item['title'] }}</span></span>
                                @endforeach
                                @if (count($d['entries']) > 3)
                                    <span class="dcal__more">{{ __('+:count more', ['count' => count($d['entries']) - 3]) }}</span>
                                @endif
                                </span>
                            </button>
                        @endforeach
                    </div>
                </section>

                <div class="dcal-side">
                    <section class="dash-card" aria-labelledby="dcal-day">
                        <div class="dash-card__head">
                            <h2 id="dcal-day">{{ \Illuminate\Support\Carbon::parse($chosen['date'])->translatedFormat('l, j F') }}</h2>
                            @if ($chosen['today'])<small>{{ __('Today') }}</small>@endif
                        </div>
                        @forelse ($chosen['entries'] as $item)
                            <a href="{{ $item['href'] }}" class="dcal-entry" wire:navigate>
                                <span class="dcal-entry__time">{{ $item['time'] ?? '' }}</span>
                                <span class="dcal-entry__text">
                                    <span class="dcal-entry__title">{{ $item['title'] }}</span>
                                    <span class="dash-role" style="--tone: {{ $tones[$item['status']] }}">{{ __($item['label'] ?? ucfirst($item['status'])) }}</span>
                                </span>
                            </a>
                        @empty
                            <p class="dash-empty">{{ __('Nothing on this day.') }}<br><a href="{{ route('admin.entries.create', ['posts', 'date' => $chosen['date']]) }}" wire:navigate>{{ __('Plan a post for this day →') }}</a></p>
                        @endforelse
                    </section>

                    <section class="dash-card" aria-labelledby="dcal-drafts">
                        <div class="dash-card__head">
                            <h2 id="dcal-drafts">{{ __('Drafts on the desk') }}</h2>
                            <a href="{{ route('admin.entries', ['posts', 'status' => 'draft']) }}" wire:navigate>{{ __('All drafts') }}</a>
                        </div>
                        @if ($drafts->isEmpty())
                            <p class="dash-empty">{{ __('No drafts waiting.') }}</p>
                        @else
                            <p class="dcal-side__hint">{{ __('Drag one onto a day to plan it.') }}</p>
                            <ul class="dcal-drafts">
                                @foreach ($drafts as $draft)
                                    <li>
                                        <a href="{{ route('admin.entries.edit', [$draft->collectionHandle(), $draft->id()]) }}" wire:navigate
                                           draggable="true" x-data x-on:dragstart="$event.dataTransfer.setData('text/booklet-entry', @js($draft->id())); $event.dataTransfer.effectAllowed = 'move'">
                                            <span class="dcal-drafts__grip" aria-hidden="true">⋮⋮</span>
                                            <span class="dcal-drafts__title">{{ $draft->title() ?: __('Untitled') }}</span>
                                            <span class="dcal-drafts__when">{{ $draft->date() ? $draft->date()->translatedFormat('j M') : __('No date') }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </div>
        </div>
    </section>
</div>
