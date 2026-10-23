@include('insert-page-builder::sections.head')
@php $src = \BookletInserts\PageBuilder\Builder::embed((string) $f->get('url', '')); @endphp
<figure class="pb-video">
    @if ($src)
        <div class="pb-video__frame">
            <iframe src="{{ $src }}" title="{{ $f->get('title') ?: 'Video' }}" loading="lazy" allowfullscreen
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    referrerpolicy="strict-origin-when-cross-origin" @if ($f->live) tabindex="-1" @endif></iframe>
        </div>
    @elseif ($f->live)
        <div class="pb-video__frame pb-video__none"><span>Paste a YouTube or Vimeo address on the left</span></div>
    @endif
    @if ($f->has('caption')) <figcaption>{{ $f->text('caption', 'span') }}</figcaption> @endif
</figure>
