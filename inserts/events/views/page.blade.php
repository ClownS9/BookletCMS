<x-site.layout :title="$insert->setting('heading')" :description="$insert->setting('intro') ?: null">
    <div class="wrap wrap--narrow section events">
        <header class="post-head">
            <h1 class="post-head__title">{{ $insert->setting('heading') }}</h1>
            @if ($insert->setting('intro')) <p class="post-head__standfirst">{{ $insert->setting('intro') }}</p> @endif
        </header>

        @include('insert-events::list', ['events' => $upcoming, 'empty' => 'Nothing coming up just now. Check back soon.', 'isPast' => false])

        @if ($upcoming->isNotEmpty())
            <p class="events__subscribe"><a href="{{ route('site.insert.events.feed') }}">Add all of these to your calendar</a> (it stays up to date)</p>
        @endif

        @if ($past->isNotEmpty())
            <h2 class="events__past">Been and gone</h2>
            @include('insert-events::list', ['events' => $past, 'empty' => '', 'isPast' => true, 'tag' => 'h3'])
        @endif
    </div>
</x-site.layout>
