@include('builder.sections.head')
<div @class(['pb-quotes', 'pb-quotes--one' => count($f->items('items')) === 1])>
    @foreach ($f->items('items') as $i => $q)
        <figure class="pb-quote"{{ $f->itemAttrs('items', $i) }}>
            {{ $q->words('words', 'pb-quote__words', 'blockquote') }}
            @if ($q->has('name', 'role', 'picture'))
                <figcaption class="pb-quote__who">
                    @if ($q->live || $q->filled('picture')) <span class="pb-quote__face">{{ $q->picture('picture', '', '64px') }}</span> @endif
                    <span>
                        {{ $q->text('name', 'cite', 'pb-quote__name') }}
                        {{ $q->text('role', 'span', 'pb-quote__role') }}
                    </span>
                </figcaption>
            @endif
        </figure>
    @endforeach
</div>
