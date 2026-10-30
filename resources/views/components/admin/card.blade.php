@props(['title' => null, 'flush' => false])
{{-- A card on the Dashboard layout's desk: a panel with an optional title.
     In the Book layout it adds nothing, so the page reads exactly as before. --}}
@if (\App\Support\YourBook::desk())
    <section {{ $attributes->class(['desk-card', 'desk-card--flush' => $flush]) }}>
        @if ($title)
            <h2 class="desk-card__title">{{ $title }}</h2>
        @endif
        <div class="desk-card__body">{{ $slot }}</div>
    </section>
@else
    {{ $slot }}
@endif
