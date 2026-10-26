{{-- After a post: the posts either side, new posts by email, and the conversation. --}}
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

<x-site.subscribe place="post" />

<x-site.comments :entry="$entry" />
