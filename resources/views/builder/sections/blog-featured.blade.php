{{-- The newest post, large (only on the blog's first page, showing every category). --}}
@php $featured = $blog['featured'] ?? null; $site = app(\App\Booklet\Site::class); @endphp
@if ($featured)
    @php $cover = $site->image($featured); $featuredCategory = $site->terms($featured, 'categories')->first(); @endphp
    <a href="{{ $site->url($featured) }}" class="feature">
        <span class="feature__picture">
            @if ($cover)
                <img src="{{ $cover }}" alt="{{ $site->imageAlt($featured) }}" fetchpriority="high" @if ($srcset = $site->imageSrcset($featured)) srcset="{{ $srcset }}" sizes="(max-width: 900px) 100vw, 900px" @endif style="object-position: {{ $site->imageFocus($featured) }}">
            @else
                <span class="card__blank" aria-hidden="true"></span>
            @endif
            {{ $f->text('label', 'span', 'sticker feature__flag') }}
        </span>
        <span class="feature__words">
            @if ($featuredCategory) <span class="sticker" style="--tilt: -2deg">{{ $featuredCategory->title() }}</span> @endif
            <span class="feature__title">{{ $featured->title() }}</span>
            <span class="feature__excerpt">{{ $site->excerpt($featured, 220) }}</span>
            <span class="card__meta">
                @if ($author = $site->author($featured)) {{ __('by :name', ['name' => $author->byline ?: $author->name]) }} · @endif
                {{ $featured->date()?->translatedFormat('j F') }} · {{ __(':count min read', ['count' => $site->readingTime($featured)]) }}
            </span>
        </span>
    </a>
@elseif ($f->live)
    <p class="pb-nothing">{{ __('The newest post shows here, large, on the first page of the blog.') }}</p>
@endif
