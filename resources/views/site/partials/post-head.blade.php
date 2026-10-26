{{-- A post's head: where it's from, its title, who wrote it and when; its cover. --}}
<header class="post-head">
    <a href="{{ $site->blogUrl() }}" class="back-link">← The blog</a>
    @if ($category)
        <p><a href="{{ url($category->attribute('uri')) }}" class="sticker" style="--tilt: -2deg">{{ $category->title() }}</a></p>
    @endif
    <h1 class="post-head__title">{{ $entry->title() }}</h1>
    @if ($entry->get('excerpt'))
        <p class="post-head__standfirst">{{ $entry->get('excerpt') }}</p>
    @endif
    <p class="byline">
        @if ($author)
            <x-site.portrait :user="$author" size="32" />
            by {{ $author->byline ?: $author->name }} ·
        @endif
        {{ $entry->date()?->format('j F Y') }} · {{ $site->readingTime($entry) }} min read
    </p>
    <x-site.languages :entry="$entry" />
</header>

@if ($cover = $site->image($entry))
    <figure class="post-cover">
        <img src="{{ $cover }}" alt="{{ $site->imageAlt($entry) }}" fetchpriority="high" @if ($srcset = $site->imageSrcset($entry)) srcset="{{ $srcset }}" sizes="(max-width: 1140px) 100vw, 1100px" @endif style="object-position: {{ $site->imageFocus($entry) }}">
        @if ($entry->get('cover_caption'))
            <figcaption>{{ $entry->get('cover_caption') }}</figcaption>
        @endif
    </figure>
@endif
