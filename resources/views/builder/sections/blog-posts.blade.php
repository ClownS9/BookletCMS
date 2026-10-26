{{-- Every post, a page at a time, with the sidebar beside them. --}}
@php
    $site = app(\App\Booklet\Site::class);
    $posts = $blog['posts'] ?? collect();
    $side = (string) $f->get('sidebar', 'right');
    $about = trim((string) $f->get('about_words')) !== '' ? (string) $f->get('about_words') : (string) $site->tagline();
@endphp
<div @class(['with-aside', 'with-aside--left' => $side === 'left'])>
    <div class="with-aside__main blog-posts blog-posts--{{ $f->get('style', 'grid') }}">
        @if ($posts->isEmpty() && empty($blog['featured']))
            {{ $f->text('empty', 'p', 'note') }}
        @else
            <div class="cards">
                @foreach ($posts as $i => $entry)
                    @include('site.partials.card', ['entry' => $entry, 'i' => $i, 'site' => $site])
                @endforeach
            </div>
        @endif
        @if (isset($blog['paginator']))
            @include('site.partials.pager', ['paginator' => $blog['paginator']])
        @endif
    </div>

    @if ($side !== 'none' && ($f->get('about') || $f->get('recent') || $f->get('tags')))
        <aside class="with-aside__side">
            @if ($f->get('about') && ($about !== '' || $f->live))
                <div class="note">
                    {{ $f->text('about_title', 'h2') }}
                    @if ($f->live)
                        {{ $f->words('about_words', '', 'p') }}
                        @if (! $f->filled('about_words') && $about !== '') <p class="pb-note-hint">(Empty: readers see the site’s tagline, “{{ $about }}”)</p> @endif
                    @else
                        <p class="pb-words">{{ $f->filled('about_words') ? $f->rich('about_words') : $about }}</p>
                    @endif
                </div>
            @endif

            @if ($f->get('recent'))
                @php $recent = $site->posts()->take((int) $f->get('recent_count', 4)); @endphp
                @if ($recent->count() > 1 || $f->live)
                    <div>
                        {{ $f->text('recent_title', 'h2', 'eyebrow') }}
                        <ul class="plain-list">
                            @foreach ($recent as $other) <li><a href="{{ $site->url($other) }}">{{ $other->title() }}</a></li> @endforeach
                        </ul>
                    </div>
                @endif
            @endif

            @if ($f->get('tags'))
                @php $cloud = $site->tagCloud(); @endphp
                @if ($cloud->isNotEmpty() || $f->live)
                    <div>
                        {{ $f->text('tags_title', 'h2', 'eyebrow') }}
                        <div class="tags">
                            @foreach ($cloud as $i => $tag)
                                <a href="{{ url($tag->attribute('uri')) }}" class="sticker" style="--tilt: {{ $i % 2 ? '2deg' : '-2deg' }}">{{ $tag->title() }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </aside>
    @endif
</div>
