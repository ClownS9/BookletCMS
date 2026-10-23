@include('insert-page-builder::sections.head')
<ul class="pb-grid pb-grid--{{ $f->get('across', '3') }} pb-columns" role="list">
    @foreach ($f->items('items') as $i => $col)
        <li class="pb-column"{{ $f->itemAttrs('items', $i) }}>
            {{ $col->text('mark', 'span', 'pb-column__mark') }}
            {{ $col->text('title', 'h3', 'pb-column__title') }}
            {{ $col->words('words') }}
        </li>
    @endforeach
</ul>
