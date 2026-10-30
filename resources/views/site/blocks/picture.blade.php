@php $picture = \App\Support\Blocks::picture($block['picture'][0] ?? null, 'media', ($block['shape'] ?? '') ?: null); @endphp
@if ($picture)
    <figure @class(['block', 'block--picture', 'block--'.$block['size']])>
        <img src="{{ $picture['url'] }}" alt="{{ $picture['alt'] }}" loading="{{ \App\Support\Pictures::loading() }}" decoding="async"
             @if ($picture['srcset']) srcset="{{ $picture['srcset'] }}" sizes="{{ $block['size'] === 'full' ? '100vw' : '(max-width: 1140px) 100vw, 1100px' }}" @endif
             style="object-position: {{ $picture['focus'] }};@isset($picture['ratio']) aspect-ratio: {{ $picture['ratio'] }}; object-fit: cover; max-height: none;@endisset">
        @if ($block['caption'] !== '') <figcaption>{{ $block['caption'] }}</figcaption> @endif
    </figure>
@endif
