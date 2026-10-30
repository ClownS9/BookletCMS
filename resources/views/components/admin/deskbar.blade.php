{{-- The Dashboard layout's top bar: your site, search, Stacy, the site itself
     and night reading. Computers only; phones keep their own bars. --}}
@php
    $user = request()->user();
    $site = rescue(fn () => \App\Booklet\Facades\Booklet::globals('site')?->get('site_name'), null, false);
    $night = (bool) ($user->preferences['night'] ?? false);
@endphp
<header class="deskbar">
    <a href="{{ route('admin.contents') }}" class="deskbar__brand" wire:navigate>
        <span class="deskbar__mark" aria-hidden="true"><x-admin.logo size="18" /></span>
        <span class="deskbar__site">{{ $site ?: __('booklet') }}</span>
    </a>

    <button type="button" class="deskbar__search" x-on:click="$dispatch('open-search')">
        <x-admin.icon name="search" size="15" />
        <span>{{ __('Search everything…') }}</span>
        <kbd>⌘K</kbd>
    </button>

    <span class="deskbar__gap"></span>

    <a href="{{ route('admin.stacy') }}" class="deskbar__stacy" wire:navigate @if (request()->routeIs('admin.stacy')) aria-current="page" @endif>
        <x-admin.icon name="stacy" size="15" />{{ __('Ask Stacy') }}
    </a>
    <a href="{{ url('/') }}" class="deskbar__link" target="_blank" rel="noopener">
        {{ __('View site') }}
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
        <span class="sr-only">{{ __('(opens in a new tab)') }}</span>
    </a>
    <form method="POST" action="{{ route('admin.night') }}" class="deskbar__night">
        @csrf
        <button type="submit" class="deskbar__icon" role="switch" aria-checked="{{ $night ? 'true' : 'false' }}" aria-label="{{ __('Night reading') }}" title="{{ __('Night reading') }}">
            <x-admin.icon :name="$night ? 'sun' : 'moon'" size="16" />
        </button>
    </form>
</header>
