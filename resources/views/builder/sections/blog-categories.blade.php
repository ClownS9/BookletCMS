{{-- Chips that show one category at a time. --}}
@php $site = app(\App\Booklet\Site::class); $categories = $blog['categories'] ?? collect(); $category = $blog['category'] ?? ''; @endphp
@if ($categories->isNotEmpty())
    <nav class="chips pb-chips" aria-label="{{ __('Categories') }}">
        <a href="{{ $site->blogUrl() }}" @class(['chip', 'chip--all']) @if ($category === '') aria-current="page" @endif{!! $f->live ? ' data-pb-field="all" data-pb-kind="text" data-pb-placeholder="All"' : '' !!}>{{ $f->say('all') }}</a>
        @foreach ($categories as $i => $term)
            <a href="{{ request()->attributes->get('booklet_static') && $term->attribute('uri') ? url($term->attribute('uri')) : $site->blogUrl().'?category='.$term->slug() }}" class="chip" style="--tilt: {{ $i % 2 ? '2deg' : '-2deg' }}" @if ($category === $term->slug()) aria-current="page" @endif>{{ $term->title() }}</a>
        @endforeach
    </nav>
@elseif ($f->live)
    <p class="pb-nothing">{{ __('Your categories show here as chips, once posts have some.') }}</p>
@endif
