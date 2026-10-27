{{-- A post's head: where it's from, its title, who wrote it and when; its cover. --}}
<header class="post-head">
    <a href="{{ $site->blogUrl() }}" class="back-link">{{ __('← The blog') }}</a>
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
            {{ __('by :name', ['name' => $author->byline ?: $author->name]) }} ·
        @endif
        {{ $entry->date()?->translatedFormat('j F Y') }} · {{ __(':count min read', ['count' => $site->readingTime($entry)]) }}
    </p>
    <x-site.languages :entry="$entry" />
    @if ($series ?? null)
        {{-- Part of a series: which part, and every part --}}
        <details class="series-box">
            <summary>
                <span class="eyebrow">{{ __('Part :n of :count', ['n' => $series['part'], 'count' => $series['count']]) }}</span>
                <span class="series-box__name">{{ $series['name'] }}</span>
                <span class="series-box__more" aria-hidden="true">{{ __('All parts') }}</span>
            </summary>
            <ol class="series-box__parts">
                @foreach ($series['parts'] as $part)
                    <li>
                        @if ($part->id() === $entry->id() || $part->id() === $entry->get('translation_of'))
                            <span aria-current="page">{{ $part->title() }}</span>
                        @else
                            <a href="{{ $site->url($part) }}">{{ $part->title() }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </details>
    @endif
</header>

@if ($cover = $site->image($entry))
    <figure class="post-cover">
        <img src="{{ $cover }}" alt="{{ $site->imageAlt($entry) }}" fetchpriority="high" @if ($srcset = $site->imageSrcset($entry)) srcset="{{ $srcset }}" sizes="(max-width: 1140px) 100vw, 1100px" @endif style="object-position: {{ $site->imageFocus($entry) }}">
        @if ($entry->get('cover_caption'))
            <figcaption>{{ $entry->get('cover_caption') }}</figcaption>
        @endif
    </figure>
@endif
