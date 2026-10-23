@php $tz = config('app.timezone'); @endphp
@if ($events->isEmpty())
    @if ($empty) <p class="events__empty">{{ $empty }}</p> @endif
@else
    <ol @class(['events__list', 'events__list--past' => $isPast ?? false])>
        @foreach ($events as $e)
            @php
                $start = \Illuminate\Support\Carbon::parse($e->field('starts'), $tz);
                $end = $e->field('ends') ? \Illuminate\Support\Carbon::parse($e->field('ends'), $tz) : null;
                $allDay = (bool) $e->field('all_day');
            @endphp
            <li id="{{ $e->slug }}" class="event">
                <time class="event__date" datetime="{{ $allDay ? $start->toDateString() : $start->toIso8601String() }}">
                    <span>{{ $start->format('M') }}</span><b>{{ $start->format('j') }}</b><span>{{ $start->format('D') }}</span>
                </time>
                <div class="event__words">
                    <{{ $tag ?? 'h2' }} class="event__title">{{ $e->field('title') }}</{{ $tag ?? 'h2' }}>
                    <p class="event__when">
                        @if ($allDay)
                            {{ $start->format('l j F') }}@if ($end && ! $end->isSameDay($start)) to {{ $end->format('l j F') }}@endif
                        @else
                            {{ $start->format('l j F, g:ia') }}@if ($end) to {{ $end->isSameDay($start) ? $end->format('g:ia') : $end->format('l j F, g:ia') }}@endif
                        @endif
                        @if ($e->field('venue')) · {{ $e->field('venue') }}@endif
                    </p>
                    @if ($e->field('address')) <p class="event__where">{{ $e->field('address') }}</p> @endif
                    @if ($e->field('about')) <p class="event__about">{{ $e->field('about') }}</p> @endif
                    @unless ($isPast ?? false)
                        <p class="event__links">
                            <a href="{{ route('site.insert.events.ics', $e->slug) }}">Add to my calendar</a>
                            @if ($e->field('link')) · <a href="{{ $e->field('link') }}" rel="noopener" target="_blank">More about it ↗</a> @endif
                        </p>
                    @endunless
                </div>
            </li>
        @endforeach
    </ol>
@endif
