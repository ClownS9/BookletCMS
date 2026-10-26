@include('builder.sections.head')
<ol class="pb-steps" role="list">
    @foreach ($f->items('items') as $i => $step)
        <li class="pb-step"{{ $f->itemAttrs('items', $i) }}>
            <span class="pb-step__n" aria-hidden="true">{{ $i + 1 }}</span>
            <div class="pb-step__words">
                {{ $step->text('title', 'h3', 'pb-step__title') }}
                {{ $step->words('words') }}
            </div>
        </li>
    @endforeach
</ol>
