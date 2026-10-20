{{-- A linked-entries field for readers: posts as cards, pages as a list. --}}
@php [$posts, $pages] = $entries->partition(fn ($e) => (bool) $e->collection()?->dated()); @endphp
<section class="linked" aria-labelledby="linked-{{ $handle }}">
    <h2 id="linked-{{ $handle }}" class="linked__title">{{ $title }}</h2>
    @if ($posts->isNotEmpty())
        <div class="cards linked__cards">
            @foreach ($posts->values() as $i => $entry)
                @include('site.partials.card', ['entry' => $entry, 'i' => $i, 'site' => $site])
            @endforeach
        </div>
    @endif
    @if ($pages->isNotEmpty())
        <ul class="linked__pages">
            @foreach ($pages as $entry)
                <li>
                    <a href="{{ $site->url($entry) }}">
                        <span class="linked__page-title">{{ $entry->title() }}</span>
                        @if ($about = $site->excerpt($entry, 120)) <span class="linked__page-about">{{ $about }}</span> @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
