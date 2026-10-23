<x-site.layout :title="request()->is('/') ? null : 'Blog'" :description="$site->tagline()">
    @php \App\Support\StructuredData::listing($category !== '' ? ($categories->first(fn ($c) => $c->slug() === $category)?->title() ?? 'Blog') : 'Blog', $featured ? collect([$featured])->merge($posts) : $posts); @endphp
    <div class="wrap section">
        <h1 class="eyebrow">@if (request()->is('/'))<span class="sr-only">{{ $site->name() }}: </span>@endif The blog</h1>

        @if ($featured)
            @php
                $cover = $site->image($featured);
                $featuredCategory = $site->terms($featured, 'categories')->first();
            @endphp
            <a href="{{ $site->url($featured) }}" class="feature">
                <span class="feature__picture">
                    @if ($cover)
                        <img src="{{ $cover }}" alt="{{ $site->imageAlt($featured) }}" fetchpriority="high" @if ($srcset = $site->imageSrcset($featured)) srcset="{{ $srcset }}" sizes="(max-width: 900px) 100vw, 900px" @endif style="object-position: {{ $site->imageFocus($featured) }}">
                    @else
                        <span class="card__blank" aria-hidden="true"></span>
                    @endif
                    <span class="sticker feature__flag" style="--tilt: -4deg; background: var(--sun)">Featured</span>
                </span>
                <span class="feature__words">
                    @if ($featuredCategory) <span class="sticker" style="--tilt: -2deg">{{ $featuredCategory->title() }}</span> @endif
                    <span class="feature__title">{{ $featured->title() }}</span>
                    <span class="feature__excerpt">{{ $site->excerpt($featured, 220) }}</span>
                    <span class="card__meta">
                        @if ($author = $site->author($featured)) by {{ $author->byline ?: $author->name }} · @endif
                        {{ $featured->date()?->format('j F') }} · {{ $site->readingTime($featured) }} min read
                    </span>
                </span>
            </a>
        @endif

        @if ($categories->isNotEmpty())
            <nav class="chips" aria-label="Categories">
                <a href="{{ $site->blogUrl() }}" @class(['chip', 'chip--all']) @if ($category === '') aria-current="page" @endif>All</a>
                @foreach ($categories as $i => $term)
                    <a href="{{ request()->attributes->get('booklet_static') && $term->attribute('uri') ? url($term->attribute('uri')) : $site->blogUrl().'?category='.$term->slug() }}" class="chip" style="--tilt: {{ $i % 2 ? '2deg' : '-2deg' }}" @if ($category === $term->slug()) aria-current="page" @endif>{{ $term->title() }}</a>
                @endforeach
            </nav>
        @endif

        <div class="with-aside">
            <div class="with-aside__main">
                @if ($posts->isEmpty() && ! $featured)
                    <p class="note">Nothing in this chapter yet.</p>
                @else
                    <div class="cards">
                        @foreach ($posts as $i => $entry)
                            @include('site.partials.card', ['entry' => $entry, 'i' => $i])
                        @endforeach
                    </div>
                @endif

                @include('site.partials.pager', ['paginator' => $paginator])

                @unless (request()->attributes->get('booklet_static')) <livewire:site.subscribe place="blog" /> @endunless
            </div>

            <aside class="with-aside__side">
                @if ($site->tagline())
                    <div class="note">
                        <h2>About this blog</h2>
                        <p>{{ $site->tagline() }}</p>
                    </div>
                @endif

                @php $recent = $site->posts()->take(4); @endphp
                @if ($recent->count() > 1)
                    <div>
                        <h2 class="eyebrow">Recently</h2>
                        <ul class="plain-list">
                            @foreach ($recent as $other)
                                <li><a href="{{ $site->url($other) }}">{{ $other->title() }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @php $cloud = $site->tagCloud(); @endphp
                @if ($cloud->isNotEmpty())
                    <div>
                        <h2 class="eyebrow">Tags</h2>
                        <div class="tags">
                            @foreach ($cloud as $i => $tag)
                                <a href="{{ url($tag->attribute('uri')) }}" class="sticker" style="--tilt: {{ $i % 2 ? '2deg' : '-2deg' }}">{{ $tag->title() }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </aside>
        </div>
    </div>
</x-site.layout>
