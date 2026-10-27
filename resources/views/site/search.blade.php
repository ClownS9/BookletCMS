<x-site.layout title="{{ __('Search') }}" robots="noindex, follow">
    <div class="wrap wrap--narrow section">
        <h1 class="sr-only">{{ __('Search') }}</h1>
        <form action="{{ route('site.search') }}" method="GET" role="search">
            <label for="q" class="eyebrow">{{ __('Search the site') }}</label>
            <div class="big-search">
                <input id="q" name="q" type="search" value="{{ $q }}" placeholder="{{ __('What are you looking for?') }}" autofocus>
                <button type="submit" aria-label="{{ __('Search') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                </button>
            </div>
            @if ($q !== '')
                <div class="search-bar">
                    <p role="status">{{ trans_choice(':count result for “:q”|:count results for “:q”', $results->count(), ['q' => $q]) }}</p>
                    <div class="chips" style="margin: 0">
                        @foreach (['all' => 'All', 'posts' => 'Posts', 'pages' => 'Pages'] as $value => $label)
                            <a href="{{ route('site.search', ['q' => $q, 'type' => $value]) }}" class="chip chip--all" @if ($type === $value) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </form>

        @if ($q !== '')
            @if ($results->isEmpty())
                <div class="note" style="margin-top: 32px">
                    <p class="serif" style="font-size: 22px">{{ __('Nothing found for “:q”.', ['q' => $q]) }}</p>
                    <p style="margin-top: 8px">{!! __('Try a shorter word, or browse :blog.', ['blog' => '<a href="'.e($site->blogUrl()).'">'.e(__('the blog')).'</a>']) !!}</p>
                </div>
            @else
                <ol class="results">
                    @foreach ($results as $entry)
                        @php
                            $title = $entry->title();
                            $at = mb_stripos($title, $q);
                        @endphp
                        <li>
                            <a href="{{ $site->url($entry) }}">
                                <span class="results__kind">
                                    <span class="sticker" style="--tilt: {{ $loop->odd ? '-2deg' : '2deg' }}; background: {{ $entry->collection()?->dated() ? 'var(--sun)' : 'var(--mint)' }}">{{ $entry->collection()?->dated() ? __('Post') : __('Page') }}</span>
                                    {{ $entry->date()?->translatedFormat('j F Y') ?? $entry->uri() }}
                                </span>
                                <span class="results__title">@if ($at !== false){{ mb_substr($title, 0, $at) }}<mark>{{ mb_substr($title, $at, mb_strlen($q)) }}</mark>{{ mb_substr($title, $at + mb_strlen($q)) }}@else{{ $title }}@endif</span>
                                <span class="results__excerpt">{{ $site->excerpt($entry, 160) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        @endif
    </div>
</x-site.layout>
