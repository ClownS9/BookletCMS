@if ($f->has('title'))
    <header class="pb-head pb-logos__head">{{ $f->text('title', 'h2', 'pb-logos__title') }}</header>
@endif
@php $logos = array_values(array_filter(array_map(fn ($p) => is_string($p) ? \App\PageBuilder\Fields::pictureFor($p) : null, (array) $f->get('pictures', [])))); @endphp
@if ($logos !== [])
    <ul @class(['pb-logos', 'pb-logos--soft' => $f->get('soft')]) role="list">
        @foreach ($logos as $logo)
            <li><img src="{{ $logo['url'] }}" alt="{{ $logo['alt'] }}" loading="lazy" decoding="async" @if ($f->live) data-pb-pick="pictures" @endif></li>
        @endforeach
    </ul>
@elseif ($f->live)
    <button type="button" class="pb-pick pb-pick--wide" data-pb-pick="pictures"><span>Choose logos from Media</span></button>
@endif
