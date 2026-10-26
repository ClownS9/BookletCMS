@include('builder.sections.head')
<ul class="pb-grid pb-grid--{{ min(4, max(1, count($f->items('items')))) }} pb-stats" role="list">
    @foreach ($f->items('items') as $i => $stat)
        <li class="pb-stat"{{ $f->itemAttrs('items', $i) }}>
            {{ $stat->text('number', 'p', 'pb-stat__number') }}
            {{ $stat->text('label', 'p', 'pb-stat__label') }}
            {{ $stat->words('words', 'pb-stat__words') }}
        </li>
    @endforeach
</ul>
