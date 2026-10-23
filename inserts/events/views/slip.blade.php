@if ($upcoming->isNotEmpty())
    @php $heading = ($heading ?? null) ?: $insert->setting('heading'); @endphp
    <aside class="events-slip" aria-label="{{ $heading }}">
        <p class="events-slip__head">{{ $heading }}</p>
        @include('insert-events::list', ['events' => $upcoming, 'empty' => '', 'isPast' => false, 'tag' => 'p'])
        <p class="events-slip__all"><a href="{{ route('site.insert.events.index') }}">Everything that’s coming up →</a></p>
    </aside>
@endif
