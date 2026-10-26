@include('builder.sections.head')
@php $cols = $f->items('items'); $n = max(1, min(4, count($cols))); @endphp
<div @class(['pb-free', 'pb-free--'.$n, 'pb-free--'.$f->get('widths', 'even') => $n === 2])>
    @foreach ($cols as $i => $col)
        <div class="pb-free__col"{{ $f->itemAttrs('items', $i) }}>
            @if ($col->live || $col->filled('picture'))
                <div class="pb-free__picture">{{ $col->picture('picture', 'pb-picture', '(max-width: 760px) 100vw, 50vw') }}</div>
            @endif
            {{ $col->text('title', 'h3', 'pb-free__title') }}
            {{ $col->words('words', 'pb-free__words') }}
            @if ($col->has('button.label'))
                <p class="pb-buttons">{{ $col->link('button', 'button') }}</p>
            @endif
        </div>
    @endforeach
</div>
