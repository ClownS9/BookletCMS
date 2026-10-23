@include('insert-page-builder::sections.head')
@php $paths = array_values(array_filter((array) $f->get('pictures', []), 'is_string')); @endphp
@if ($paths !== [])
    <ul class="pb-grid pb-grid--{{ $f->get('across', '3') }} pb-gallery" role="list">
        @foreach ($paths as $path)
            @php $pic = \BookletInserts\PageBuilder\Fields::pictureFor($path); @endphp
            @if ($pic)
                <li>
                    <figure>
                        <img src="{{ $pic['url'] }}" alt="{{ $pic['alt'] }}" loading="lazy" decoding="async" style="object-position: {{ $pic['focus'] }}"
                             @if ($pic['srcset'] !== '') srcset="{{ $pic['srcset'] }}" sizes="(max-width: 760px) 50vw, 380px" @endif
                             @if ($f->live) data-pb-pick="pictures" @endif>
                        @if ($f->get('captions') && $pic['alt'] !== '') <figcaption>{{ $pic['alt'] }}</figcaption> @endif
                    </figure>
                </li>
            @endif
        @endforeach
    </ul>
@elseif ($f->live)
    <button type="button" class="pb-pick pb-pick--wide" data-pb-pick="pictures"><span>Choose pictures from Media</span></button>
@endif
