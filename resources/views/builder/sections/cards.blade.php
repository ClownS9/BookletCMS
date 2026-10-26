@include('builder.sections.head')
<ul class="pb-grid pb-grid--{{ $f->get('across', '3') }} pb-cards" role="list">
    @foreach ($f->items('items') as $i => $card)
        @php $url = trim((string) $card->get('url')); @endphp
        <li class="pb-card"{{ $f->itemAttrs('items', $i) }}>
            @if ($card->live || $card->filled('picture'))
                <div class="pb-card__picture">{{ $card->picture('picture', '', '(max-width: 760px) 100vw, 380px') }}</div>
            @endif
            <div class="pb-card__words">
                @if ($url !== '' && ! $card->live)
                    <h3 class="pb-card__title"><a href="{{ $url }}">{{ $card->get('title') }}</a></h3>
                @else
                    {{ $card->text('title', 'h3', 'pb-card__title') }}
                @endif
                {{ $card->words('words') }}
            </div>
        </li>
    @endforeach
</ul>
