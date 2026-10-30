@php
    $shape = ($block['shape'] ?? '') ?: null;
    $pictures = collect($block['pictures'])->map(fn ($p) => \App\Support\Blocks::picture($p, 'media', $shape))->filter()->values();
    $ratio = $pictures->first()['ratio'] ?? null;
@endphp
@if ($pictures->isNotEmpty())
    <figure class="block block--gallery" style="--count: {{ min(3, $pictures->count()) }};@if ($ratio) --shape: {{ $ratio }};@else --shape: auto;@endif">
        <ul>
            @foreach ($pictures as $i => $picture)
                <li style="--tilt: {{ ['-1.2deg', '0.8deg', '-0.5deg', '1.4deg'][$i % 4] }}">
                    <img src="{{ $picture['url'] }}" alt="{{ $picture['alt'] }}" loading="{{ \App\Support\Pictures::loading() }}" decoding="async"
                         @if ($picture['srcset']) srcset="{{ $picture['srcset'] }}" sizes="(max-width: 760px) 100vw, 400px" @endif
                         style="object-position: {{ $picture['focus'] }}">
                </li>
            @endforeach
        </ul>
        @if ($block['caption'] !== '') <figcaption>{{ $block['caption'] }}</figcaption> @endif
    </figure>
@endif
