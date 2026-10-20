@php $columns = (int) $insert->setting('columns', 3); $lightbox = (bool) $insert->setting('lightbox', true); $captions = (bool) $insert->setting('captions', true); @endphp
<figure class="gallery" style="--gallery-columns: {{ max(2, min(4, $columns)) }}" @if ($lightbox) data-gallery @endif aria-label="{{ $album->field('title') }}">
    @if ($album->field('about'))
        <figcaption class="gallery__about">{{ $album->field('about') }}</figcaption>
    @endif
    <ul class="gallery__grid">
        @foreach ($pictures as $i => $pic)
            <li>
                @if ($lightbox)
                    <a href="{{ $pic['url'] }}" class="gallery__open" data-full="{{ $pic['url'] }}" data-alt="{{ $pic['alt'] }}"
                       aria-label="{{ $pic['alt'] ?: 'Picture '.($i + 1) }}, {{ $i + 1 }} of {{ count($pictures) }}, open large">
                @endif
                <img src="{{ $pic['thumb'] }}" @if ($pic['srcset']) srcset="{{ $pic['srcset'] }}" sizes="(max-width: 760px) 50vw, 300px" @endif alt="{{ $pic['alt'] }}" loading="lazy" decoding="async">
                @if ($lightbox) </a> @endif
                @if ($captions && $pic['alt']) <span class="gallery__caption">{{ $pic['alt'] }}</span> @endif
            </li>
        @endforeach
    </ul>
</figure>
