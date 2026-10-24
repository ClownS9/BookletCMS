<div class="pb-cta">
    {{ $f->text('title', 'h2', 'pb-title') }}
    {{ $f->words('words', 'pb-lede') }}
    @if ($f->has('button.label', 'button2.label'))
        <p class="pb-buttons">{{ $f->link('button', 'button button--ink') }} {{ $f->link('button2', 'button') }}</p>
    @endif
</div>
