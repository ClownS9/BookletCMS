{{-- Phones and tablets: a top bar and a "table of contents" drawer instead of
     the sticker flags; phones also get a tab bar along the bottom. --}}
@php
    $chapters = \App\Support\AdminNav::chapters();
    $current = \App\Support\AdminNav::current();
    $tabs = collect($chapters)->whereIn('key', ['contents', 'posts', 'pages', 'media'])->values();
    $moreActive = ! $tabs->contains('active', true) && ! request()->routeIs('admin.stacy');
    $groups = ['main' => 'Chapters', 'tools' => 'Tools', 'you' => 'You & the site'];
    $user = request()->user();
@endphp

<div class="handheld" x-data="{ open: false }"
     x-on:keydown.escape.window="open = false"
     x-on:livewire:navigating.window="open = false"
     x-effect="document.documentElement.classList.toggle('drawer-open', open)">

    <header class="topbar">
        <button type="button" class="topbar__btn" x-on:click="open = true" :aria-expanded="open.toString()" aria-controls="toc-drawer">
            <x-admin.icon name="menu" size="20" /><span class="sr-only">{{ __('Open the table of contents') }}</span>
        </button>

        <a href="{{ route('admin.contents') }}" class="topbar__brand" wire:navigate aria-label="{{ __('Booklet, Contents') }}">
            <x-admin.logo size="22" />
            <span class="topbar__name">{{ __('booklet') }}</span>
        </a>

        <span class="topbar__title" aria-hidden="true">{{ $current }}</span>

        <span class="topbar__tools">
            <a href="#search" class="topbar__btn" x-on:click.prevent="$dispatch('open-search')" aria-label="{{ __('Search everything') }}">
                <x-admin.icon name="search" size="19" />
            </a>
            <a href="{{ route('admin.stacy') }}" class="topbar__stacy" aria-label="{{ __('Ask Stacy') }}" wire:navigate @if (request()->routeIs('admin.stacy')) aria-current="page" @endif>
                <x-admin.icon name="stacy" size="16" /><span>{{ __('Ask Stacy') }}</span>
            </a>
        </span>
    </header>

    {{-- The table of contents --}}
    <div class="toc-drawer" id="toc-drawer" x-show="open" x-cloak role="dialog" aria-modal="true" aria-label="{{ __('Table of contents') }}">
        <div class="toc-drawer__backdrop" x-show="open" x-transition.opacity x-on:click="open = false"></div>

        <div class="toc-drawer__panel" x-show="open"
             x-transition:enter="drawer-in" x-transition:enter-start="drawer-in--from"
             x-transition:leave="drawer-out" x-transition:leave-end="drawer-in--from"
             x-trap.noscroll="open">
            <span class="toc-drawer__grip" aria-hidden="true"></span>

            <div class="toc-drawer__head">
                <a href="{{ route('admin.account') }}" class="toc-drawer__plate" wire:navigate>
                    <x-admin.avatar :user="$user" size="44" presence />
                    <span style="min-width: 0">
                        <span class="eyebrow" style="display: block; font-size: 10px">{{ __('This book belongs to') }}</span>
                        <span class="serif" style="display: block; font-size: 18px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $user->name }}</span>
                    </span>
                </a>
                <button type="button" class="topbar__btn" x-on:click="open = false"><x-admin.icon name="close" size="20" /><span class="sr-only">{{ __('Close') }}</span></button>
            </div>

            <a href="{{ route('admin.stacy') }}" class="toc-drawer__stacy" wire:navigate>
                <span class="toc-drawer__stacy-mark"><x-admin.icon name="stacy" size="18" /></span>
                <span><strong>{{ __('Ask Stacy') }}</strong><small>{{ __('Find things, or have her do small jobs') }}</small></span>
                <span aria-hidden="true">→</span>
            </a>

            @foreach ($groups as $group => $heading)
                <p class="toc-drawer__group">{{ $heading }}</p>
                <ul class="toc-drawer__list">
                    @foreach (collect($chapters)->where('group', $group) as $i => $chapter)
                        <li>
                            <a href="{{ $chapter['href'] }}" wire:navigate @if ($chapter['active']) aria-current="page" @endif style="--tone: var(--{{ $chapter['tone'] }})">
                                <span class="toc-drawer__sticker" style="--tilt: {{ $loop->odd ? '-5deg' : '4deg' }}"><x-admin.icon :name="$chapter['icon']" size="16" /></span>
                                <span class="toc-drawer__label">
                                    <span>{{ $chapter['label'] }}</span>
                                    <small>{{ $chapter['hint'] }}</small>
                                </span>
                                @if ($chapter['badge'])
                                    <span class="toc-drawer__badge">{{ $chapter['badge'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endforeach

            <form method="POST" action="{{ route('logout') }}" class="toc-drawer__foot">
                @csrf
                <span class="muted" style="font-size: 13px">{{ __('Signed in as :name', ['name' => $user->email]) }}</span>
                <button type="submit" class="btn btn--quiet" style="min-height: 40px"><x-admin.icon name="logout" size="16" /> {{ __('Sign out') }}</button>
            </form>
        </div>
    </div>

    {{-- Phones: the tab bar --}}
    <nav class="tabbar" aria-label="{{ __('Chapters') }}">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['href'] }}" wire:navigate class="tabbar__tab" style="--tone: var(--{{ $tab['tone'] }})" @if ($tab['active']) aria-current="page" @endif>
                <span class="tabbar__icon"><x-admin.icon :name="$tab['icon']" size="20" /></span>
                <span>{{ $tab['label'] }}</span>
            </a>
        @endforeach
        <button type="button" class="tabbar__tab" style="--tone: var(--sand)" x-on:click="open = true" @if ($moreActive) aria-current="page" @endif>
            <span class="tabbar__icon">
                <x-admin.icon name="more" size="20" />
                @if ($waiting = collect($chapters)->firstWhere('key', 'comments')['badge'] ?? null)
                    <span class="tabbar__dot" aria-label="{{ $waiting }} comments waiting"></span>
                @endif
            </span>
            <span>{{ __('More') }}</span>
        </button>
    </nav>
</div>
