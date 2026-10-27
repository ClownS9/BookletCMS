{{-- A post card. Expects $entry, $site, and optionally $i for the sticker tilt. --}}
@php
    $tilts = ['-3deg', '2deg', '-2deg', '3deg', '-1deg', '2deg'];
    $category = $site->terms($entry, 'categories')->first();
    $cover = $site->image($entry);
    // The "card" size, cut around the focal point (smaller to download), when there is one.
    $cut = $cover && isset(\App\Support\Crops::sizes()['card']) ? $site->crop($entry, 'card') : null;
@endphp
<a href="{{ $site->url($entry) }}" class="card">
    <span class="card__picture">
        @if ($cover)
            @if ($cut && $cut['cropped'])
                <img src="{{ $cut['url'] }}" alt="{{ $cut['alt'] }}" loading="lazy" decoding="async" @if ($cut['srcset']) srcset="{{ $cut['srcset'] }}" sizes="(max-width: 700px) 100vw, (max-width: 1100px) 50vw, 380px" @endif width="{{ $cut['width'] }}" height="{{ $cut['height'] }}">
            @else
                <img src="{{ $cover }}" alt="{{ $site->imageAlt($entry) }}" loading="lazy" decoding="async" @if ($srcset = $site->imageSrcset($entry)) srcset="{{ $srcset }}" sizes="(max-width: 700px) 100vw, (max-width: 1100px) 50vw, 380px" @endif style="object-position: {{ $site->imageFocus($entry) }}">
            @endif
        @else
            <span class="card__blank" aria-hidden="true"></span>
        @endif
        @if ($category)
            <span class="sticker card__sticker" style="--tilt: {{ $tilts[($i ?? 0) % count($tilts)] }}">{{ $category->title() }}</span>
        @endif
    </span>
    <span class="card__title">{{ $entry->title() }}</span>
    <span class="card__excerpt">{{ $site->excerpt($entry, 150) }}</span>
    <span class="card__meta">{{ $entry->date()?->translatedFormat('j F Y') }} · {{ __(':count min read', ['count' => $site->readingTime($entry)]) }}</span>
</a>
