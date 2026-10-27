{{-- After a post: the parts of its series either side (or the posts either side), more like it, new posts by email, and the conversation. --}}
@if ($series ?? null)
    <nav class="turn turn--series" aria-label="{{ $series['name'] }}">
        @if ($series['previous'])
            <a href="{{ $site->url($series['previous']) }}"><span class="eyebrow">← Part {{ $series['part'] - 1 }} of {{ $series['count'] }}</span><span class="serif">{{ $series['previous']->title() }}</span></a>
        @else
            <span></span>
        @endif
        @if ($series['next'])
            <a href="{{ $site->url($series['next']) }}" class="turn__next"><span class="eyebrow">Part {{ $series['part'] + 1 }} of {{ $series['count'] }} →</span><span class="serif">{{ $series['next']->title() }}</span></a>
        @else
            <span class="turn__end"><span class="eyebrow">The last part</span><span>That’s all of {{ $series['name'] }}.</span></span>
        @endif
    </nav>
@elseif ($newer || $older)
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

@if (($related ?? collect())->isNotEmpty())
    <section class="more-like" aria-labelledby="more-like-title">
        <h2 id="more-like-title" class="eyebrow">More like this</h2>
        <div class="cards cards--more">
            @foreach ($related as $i => $other)
                @include('site.partials.card', ['entry' => $other, 'site' => $site, 'i' => $i])
            @endforeach
        </div>
    </section>
@endif

<x-site.subscribe place="post" />

<x-site.comments :entry="$entry" />
