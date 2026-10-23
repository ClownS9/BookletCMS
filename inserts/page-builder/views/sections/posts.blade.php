@php
    $site = app(\App\Booklet\Site::class);
    $category = (string) $f->get('category', '');
    $posts = $site->posts($category !== '' ? 'categories' : null, $category ?: null)->take((int) $f->get('count', 3));
@endphp
@if ($f->has('title', 'more'))
    <header class="pb-head pb-head--row">
        {{ $f->text('title', 'h2', 'pb-title') }}
        @if ($f->live)
            {{ $f->text('more', 'span', 'pb-more') }}
        @elseif ($f->filled('more'))
            <a class="pb-more" href="{{ $category !== '' ? $site->blogUrl().'?category='.$category : $site->blogUrl() }}">{{ $f->get('more') }} →</a>
        @endif
    </header>
@endif
@if ($posts->isEmpty())
    @if ($f->live) <p class="pb-nothing">No posts to show yet. They appear here as they’re published.</p> @endif
@else
    <div class="cards pb-posts">
        @foreach ($posts as $i => $entry)
            @include('site.partials.card', ['entry' => $entry, 'i' => $i, 'site' => $site])
        @endforeach
    </div>
@endif
