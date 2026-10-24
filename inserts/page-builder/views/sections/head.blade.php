{{-- A section's heading and the line under it. --}}
@if ($f->has('title', 'intro'))
    <header class="pb-head">
        {{ $f->text('title', 'h2', 'pb-title') }}
        {{ $f->words('intro', 'pb-lede') }}
    </header>
@endif
