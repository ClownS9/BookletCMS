<x-site.layout :title="$term->title()" :description="$term->get('description')">
    @php \App\Support\StructuredData::listing($term->title(), $paginator->getCollection(), $term); @endphp
    <div class="wrap wrap--narrow section">
        <a href="{{ $site->blogUrl() }}" class="back-link">← The blog</a>
        <div class="archive-head">
            <div>
                <p class="eyebrow">{{ $taxonomy?->title() ?? 'Chapter' }}</p>
                <h1>{{ $term->title() }}</h1>
            </div>
            <span class="archive-head__count" aria-label="{{ $total }} posts">{{ $total }}</span>
        </div>
        @if ($term->get('description'))
            <p class="lede">{{ $term->get('description') }}</p>
        @endif

        @if ($paginator->isEmpty())
            <p class="note" style="margin-top: 32px">Nothing in this chapter yet.</p>
        @else
            <ol class="index">
                @foreach ($paginator as $i => $entry)
                    @php $cover = $site->image($entry); @endphp
                    <li>
                        <a href="{{ $site->url($entry) }}" class="index__row">
                            <span class="index__date">{{ $entry->date()?->format('j M Y') }}</span>
                            <span>
                                <span class="index__title">{{ $entry->title() }}</span>
                                <span class="index__excerpt">{{ $site->excerpt($entry, 140) }}</span>
                            </span>
                            @if ($cover)
                                <span class="index__picture" style="--tilt: {{ $i % 2 ? '2deg' : '-2deg' }}"><img src="{{ $cover }}" alt="" loading="lazy" decoding="async" @if ($srcset = $site->imageSrcset($entry)) srcset="{{ $srcset }}" sizes="160px" @endif style="object-position: {{ $site->imageFocus($entry) }}"></span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif

        @include('site.partials.pager', ['paginator' => $paginator])
    </div>
</x-site.layout>
