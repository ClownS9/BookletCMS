<x-site.layout type="article" :title="$entry->get('meta_title') ?: $entry->title()" :description="$site->description($entry)" :image-alt="$site->imageAlt($entry) ?: null" :image="$site->shareImage($entry) ?? \App\Support\ShareImage::urlFor($entry)">
    @php \App\Support\StructuredData::post($entry); @endphp
    @if (($sections ?? null) !== null)
        {{-- Built of sections: its head, then the sections (full width), then what follows a post --}}
        <article class="pb-post">
            <div class="wrap section pb-post__head">
                @include('site.partials.post-head')
            </div>
            <div class="pb-page" data-pb-page="{{ $entry->id() }}">
                @include('builder.sections', ['live' => false, 'embedded' => false, 'h1' => false])
            </div>
            <div class="wrap section">
                @if ($tags->isNotEmpty())
                    <div class="tags">
                        @foreach ($tags as $i => $tag)
                            <a href="{{ url($tag->attribute('uri')) }}" class="sticker" style="--tilt: {{ $i % 2 ? '2deg' : '-2deg' }}">{{ $tag->title() }}</a>
                        @endforeach
                    </div>
                @endif
                @include('site.partials.post-end')
            </div>
        </article>
    @else
        <article class="wrap section">
            @include('site.partials.post-head')

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
                                    <span class="eyebrow" style="display: block">{{ __('Written by') }}</span>
                                    <span class="serif" style="display: block; font-size: 20px">{{ $author->byline ?: $author->name }}</span>
                                </span>
                            </div>
                            @if ($author->bio) <p>{{ $author->bio }}</p> @endif
                        </div>
                    @endif
                </aside>
            </div>

            @include('site.partials.post-end')
        </article>
    @endif

    @if ($builder ?? null)
        {{-- People who can build it: "Edit this page" (or "Build this page"), and the editor. Readers never get either. --}}
        @include('builder.editor', $builder)
    @endif
</x-site.layout>
