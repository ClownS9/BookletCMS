@php $layout = $f->get('layout', 'side'); $pic = $layout !== 'none' && ($f->live || $f->filled('picture')); @endphp
<div @class(['pb-hero', 'pb-hero--'.$layout, 'pb-hero--with-picture' => $pic])>
    <div class="pb-hero__words">
        {{ $f->text('eyebrow', 'p', 'pb-eyebrow') }}
        {{ $f->text('title', $h1 ? 'h1' : 'h2', 'pb-title pb-title--big') }}
        {{ $f->words('lede', 'pb-lede') }}
        @if ($f->has('button.label', 'button2.label'))
            <p class="pb-buttons">{{ $f->link('button', 'button button--ink') }} {{ $f->link('button2', 'button') }}</p>
        @endif
    </div>
    @if ($pic)
        <div class="pb-hero__picture">{{ $f->picture('picture', 'pb-picture', $layout === 'below' ? '(max-width: 1200px) 100vw, 1150px' : '(max-width: 760px) 100vw, 560px', $first) }}</div>
    @endif
</div>
