{{-- The Dashboard layout's top bar, on computers: your site and Booklet's
     version, the section you're in (⇕ opens the pages beside it), search,
     Stacy, the site itself, night reading and your own menu on your picture.
     Phones keep their own bars. --}}
@php
    $user = request()->user();
    $site = rescue(fn () => \App\Booklet\Facades\Booklet::globals('site')?->get('site_name'), null, false);
    $night = (bool) ($user->preferences['night'] ?? false);
    $narrow = (bool) ($user->preferences['sidebar_narrow'] ?? false);
    $switch = \App\Support\AdminNav::switcher();
    $version = \App\Support\Updates::current();
    $newer = $user->isAdmin() ? rescue(fn () => \App\Support\Updates::available(), null, false) : null;
@endphp
<header class="deskbar">
    <button type="button" class="deskbar__icon deskbar__fold" x-data="bookletSidebarFold(@js($narrow), @js(route('admin.sidebar')))"
            x-on:click="toggle()" :aria-expanded="(! narrow).toString()" aria-expanded="{{ $narrow ? 'false' : 'true' }}" aria-controls="desk-sidebar"
            :aria-label="narrow ? @js(__('Open the sidebar')) : @js(__('Fold the sidebar'))" aria-label="{{ $narrow ? __('Open the sidebar') : __('Fold the sidebar') }}"
            :title="narrow ? @js(__('Open the sidebar')) : @js(__('Fold the sidebar'))" title="{{ $narrow ? __('Open the sidebar') : __('Fold the sidebar') }}">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M9 4v16"/><path class="deskbar__fold-arrow" d="M15.5 10 13.5 12l2 2"/></svg>
    </button>
    <a href="{{ route('admin.contents') }}" class="deskbar__brand" wire:navigate>
        <span class="deskbar__mark" aria-hidden="true"><x-admin.logo size="16" /></span>
        <span class="deskbar__site">{{ $site ?: __('booklet') }}</span>
    </a>
    @if ($newer)
        <a href="{{ route('admin.settings.updates') }}" class="deskbar__badge is-new" wire:navigate title="{{ __('Booklet :version is out', ['version' => $newer['version']]) }}">{{ __('Update') }}</a>
    @else
        <span class="deskbar__badge" title="{{ __('Booklet :version', ['version' => $version]) }}">{{ $version }}</span>
    @endif

    @if ($switch)
        <span class="deskbar__slash" aria-hidden="true">/</span>
        <nav class="deskbar__crumb" aria-label="{{ __('Where you are') }}">
            <x-admin.crumb-switch :switch="$switch" menu-id="desk-switch-menu" />
        </nav>
    @endif

    <span class="deskbar__gap"></span>

    <button type="button" class="deskbar__search" x-on:click="$dispatch('open-search')">
        <x-admin.icon name="search" size="15" />
        <span>{{ __('Search') }}</span>
        <kbd>⌘K</kbd>
    </button>
    <a href="{{ route('admin.stacy') }}" class="deskbar__stacy" data-stacy-dock aria-controls="orb-card" wire:navigate @if (request()->routeIs('admin.stacy')) aria-current="page" @endif>
        <x-admin.icon name="stacy" size="15" />{{ __('Ask Stacy') }}
    </a>
    <a href="{{ url('/') }}" class="deskbar__icon" target="_blank" rel="noopener" title="{{ __('View site') }}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
        <span class="sr-only">{{ __('View site') }} {{ __('(opens in a new tab)') }}</span>
    </a>
    <form method="POST" action="{{ route('admin.night') }}" class="deskbar__night">
        @csrf
        <button type="submit" class="deskbar__icon" role="switch" aria-checked="{{ $night ? 'true' : 'false' }}" aria-label="{{ __('Night reading') }}" title="{{ __('Night reading') }}">
            <x-admin.icon :name="$night ? 'sun' : 'moon'" size="16" />
        </button>
    </form>
    <div class="deskbar__me" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" x-on:livewire:navigating.window="open = false">
        <button type="button" class="deskbar__avatar" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-expanded="false" aria-controls="desk-me-menu" title="{{ $user->name }}">
            <x-admin.avatar :user="$user" size="30" />
            <span class="sr-only">{{ __('Your menu') }}</span>
        </button>
        <div class="sidebar__menu" id="desk-me-menu" x-show="open" x-cloak
                 x-transition:enter="flag-note-in" x-transition:enter-start="sidebar__menu--from" x-transition:leave="flag-note-out" x-transition:leave-end="sidebar__menu--from">
                <p class="sidebar__signed">{{ __('Signed in as') }} <b>{{ $user->email }}</b></p>
                <a href="{{ route('admin.account') }}" wire:navigate @if (request()->routeIs('admin.account')) aria-current="page" @endif><x-admin.icon name="user" size="16" /> {{ __('Your account') }}</a>
                <a href="{{ route('admin.account.yours') }}" wire:navigate @if (request()->routeIs('admin.account.yours')) aria-current="page" @endif><x-admin.icon name="book" size="16" /> {{ __('Make it yours') }}</a>
                <a href="{{ route('admin.account.menu') }}" wire:navigate @if (request()->routeIs('admin.account.menu')) aria-current="page" @endif><x-admin.icon name="list" size="16" /> {{ __('Your menu') }}</a>
                <form method="POST" action="{{ route('admin.night') }}">
                    @csrf
                    <button type="submit" role="switch" aria-checked="{{ ($user->preferences['night'] ?? false) ? 'true' : 'false' }}">
                        <x-admin.icon name="moon" size="16" /><span>{{ __('Night reading') }}</span><span class="switch switch--sm" aria-hidden="true"></span>
                    </button>
                </form>
                <button type="button" x-on:click="open = false; $dispatch('open-shortcuts')"><span class="sidebar__key" aria-hidden="true">?</span><span>{{ __('Keyboard shortcuts') }}</span></button>
                <hr>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="is-danger"><x-admin.icon name="logout" size="16" /> {{ __('Sign out') }}</button>
                </form>
            </div>
    </div>
</header>
