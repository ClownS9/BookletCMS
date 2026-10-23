<button type="button" class="btn btn--ink" style="align-self: flex-start" wire:click="act('new')"><x-admin.icon name="plus" size="16" /> New event</button>

@foreach (['Coming up' => $upcoming, 'Been and gone' => $past] as $label => $list)
    @if ($list->isNotEmpty())
        <div>
            <h2 class="eyebrow section-label">{{ $label }} · {{ $list->count() }}</h2>
            <ul class="picks">
                @foreach ($list->take(30) as $e)
                    @php [$start] = $when($e); @endphp
                    <li wire:key="ev-{{ $e->id }}">
                        <button type="button" class="pick" wire:click="act('open', {{ $e->id }})" aria-pressed="{{ $event?->id === $e->id ? 'true' : 'false' }}" style="align-items: center">
                            <span class="event-date" aria-hidden="true"><b>{{ $start?->format('j') }}</b>{{ $start?->format('M') }}</span>
                            <span style="flex: 1 1 auto; min-width: 0">
                                <span class="pick__title" style="font-size: 17px">{{ $e->field('title') }}</span>
                                <span class="pick__meta">{{ $start?->format('D j M Y') }}@unless ($e->field('all_day')) · {{ $start?->format('g:ia') }}@endunless @if ($e->field('venue')) · {{ $e->field('venue') }} @endif</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endforeach

@if ($upcoming->isEmpty() && $past->isEmpty())
    <p class="empty-note">Nothing on yet. Add the first event on the right.</p>
@endif

<p class="field__hint">On the site: <a href="{{ route('site.insert.events.index') }}" target="_blank" rel="noopener">/events ↗</a>. Add it to a menu in Site menus.</p>

<style>
    .event-date { flex: none; width: 46px; padding: 4px 0 5px; border-radius: 8px; background: color-mix(in srgb, var(--coral) 22%, var(--paper)); text-align: center; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); line-height: 1.1; }
    .event-date b { display: block; font-family: var(--serif); font-size: 21px; font-weight: 500; letter-spacing: 0; color: var(--ink); }
</style>
