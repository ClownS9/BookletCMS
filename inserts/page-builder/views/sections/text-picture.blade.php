@php $pic = $f->live || $f->filled('picture'); @endphp
<div @class(['pb-split', 'pb-split--'.$f->get('side', 'right'), 'pb-split--alone' => ! $pic])>
    <div class="pb-split__words">
        {{ $f->text('eyebrow', 'p', 'pb-eyebrow') }}
        {{ $f->text('title', 'h2', 'pb-title') }}
        {{ $f->words('body', 'pb-body') }}
        @if ($f->has('button.label'))
            <p class="pb-buttons">{{ $f->link('button', 'button') }}</p>
        @endif
    </div>
    @if ($pic)
        <div class="pb-split__picture">{{ $f->picture('picture', 'pb-picture', '(max-width: 760px) 100vw, 560px') }}</div>
    @endif
</div>
