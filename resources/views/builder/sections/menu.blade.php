@include('builder.sections.head')
<div class="pb-menu pb-menu--{{ $f->get('across', '2') === '1' ? '1' : '2' }}">
    @foreach ($f->items('items') as $i => $thing)
        @if ($thing->filled('group'))
            {{ $thing->text('group', 'h3', 'pb-menu__group') }}
        @endif
        <div class="pb-menu__item"{{ $f->itemAttrs('items', $i) }}>
            <p class="pb-menu__line">
                {{ $thing->text('name', 'span', 'pb-menu__name') }}
                {{ $thing->text('tag', 'span', 'pb-menu__tag') }}
                <span class="pb-menu__dots" aria-hidden="true"></span>
                {{ $thing->text('price', 'span', 'pb-menu__price') }}
            </p>
            {{ $thing->words('words', 'pb-menu__words') }}
        </div>
    @endforeach
</div>
