<x-site.layout type="article" :title="$entry->get('meta_title') ?: $entry->title()" :description="$site->description($entry)" :image-alt="$site->imageAlt($entry) ?: null" :image="$site->shareImage($entry) ?? \App\Support\ShareImage::urlFor($entry)">
    @php \App\Support\StructuredData::post($entry); @endphp
    <article class="wrap section">
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

        <div class="with-aside">
            <div class="with-aside__main">
                <div class="prose prose--dropcap" data-reading>
                    {{ $site->markdown($entry->get('content')) }}
                </div>
                {{ $site->blocks($entry) }}
                {{ $site->extras($entry) }}

                @if ($tags->isNotEmpty())
                    <div class="tags" style="margin-top: 32px">
                        @foreach ($tags as $i => $tag)
                            <a href="{{ url($tag->attribute('uri')) }}" class="sticker" style="--tilt: {{ $i % 2 ? '2deg' : '-2deg' }}">{{ $tag->title() }}</a>
                        @endforeach
                    </div>
                @endif
            </div>

            <aside class="with-aside__side">
                @if ($author)
                    <div class="author-card">
                        <div style="display: flex; align-items: center; gap: 12px">
                            <x-site.portrait :user="$author" size="48" />
                            <span>
                                <span class="eyebrow" style="display: block">Written by</span>
                                <span class="serif" style="display: block; font-size: 20px">{{ $author->byline ?: $author->name }}</span>
                            </span>
                        </div>
                        @if ($author->bio) <p>{{ $author->bio }}</p> @endif
                    </div>
                @endif

                @if ($related->isNotEmpty())
                    <div>
                        <h2 class="eyebrow">More to read</h2>
                        <ul class="plain-list">
                            @foreach ($related as $other)
                                <li><a href="{{ $site->url($other) }}">{{ $other->title() }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </aside>
        </div>

        @if ($newer || $older)
            <nav class="turn" aria-label="More posts">
                @if ($older)
                    <a href="{{ $site->url($older) }}"><span class="eyebrow">← Previous page</span><span class="serif">{{ $older->title() }}</span></a>
                @else
                    <span></span>
                @endif
                @if ($newer)
                    <a href="{{ $site->url($newer) }}" class="turn__next"><span class="eyebrow">Next page →</span><span class="serif">{{ $newer->title() }}</span></a>
                @endif
            </nav>
        @endif

        @unless (request()->attributes->get('booklet_static')) <livewire:site.subscribe place="post" /> @endunless

        @if ($site->commentsOpen() && ! request()->attributes->has('booklet_proof') && ! request()->attributes->get('booklet_static'))
            <livewire:site.comments :entry-id="$entry->id()" />
        @endif
    </article>
</x-site.layout>
