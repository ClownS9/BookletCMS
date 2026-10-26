<div class="pb-heading">
    {{ $f->text('eyebrow', ($h1 && ! $f->filled('title') && ! $f->live) ? 'h1' : 'p', 'eyebrow pb-eyebrow') }}
    {{ $f->text('title', $h1 ? 'h1' : 'h2', 'pb-title pb-heading__title') }}
    {{ $f->words('lede', 'pb-lede') }}
</div>
