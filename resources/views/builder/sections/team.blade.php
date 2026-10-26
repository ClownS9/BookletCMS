@include('builder.sections.head')
<ul class="pb-grid pb-grid--{{ $f->get('across', '3') }} pb-team" role="list">
    @foreach ($f->items('items') as $i => $person)
        <li class="pb-person"{{ $f->itemAttrs('items', $i) }}>
            @if ($person->live || $person->filled('picture'))
                <div class="pb-person__picture">{{ $person->picture('picture', '', '(max-width: 640px) 60vw, 220px') }}</div>
            @endif
            {{ $person->text('name', 'h3', 'pb-person__name') }}
            {{ $person->text('role', 'p', 'pb-person__role') }}
            {{ $person->words('words', 'pb-person__words') }}
        </li>
    @endforeach
</ul>
