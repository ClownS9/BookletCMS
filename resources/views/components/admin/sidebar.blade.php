{{-- The Dashboard layout's sidebar: the stickers laid down the side, the tools
     under them, and at the foot who else is here and your own menu. --}}
@php
    $user = request()->user();
    $stickers = \App\Support\AdminNav::menuIn('sticker');
    $tools = \App\Support\AdminNav::menuIn('thumb');
    $waitingComments = collect(\App\Support\AdminNav::waiting())->firstWhere('key', 'comments')['count'] ?? 0;
    $here = rescue(fn () => \App\Models\User::query()->whereKeyNot($user->id)->where('last_seen_at', '>', now()->subMinutes(5))->orderByDesc('last_seen_at')->take(3)->get(), collect(), false);
    $site = rescue(fn () => \App\Booklet\Facades\Booklet::globals('site')?->get('site_name'), null, false);
@endphp
<aside class="sidebar" aria-label="{{ __('Booklet') }}">
    <a href="{{ route('admin.contents') }}" class="sidebar__brand" wire:navigate>
        <x-admin.logo size="22" />
        <span><span class="sidebar__site">{{ $site ?: __('booklet') }}</span><span class="sidebar__by">{{ __('booklet') }}</span></span>
    </a>

    <a href="{{ route('admin.stacy') }}" class="sidebar__stacy" wire:navigate @if (request()->routeIs('admin.stacy')) aria-current="page" @endif>
        <x-admin.icon name="stacy" size="16" />{{ __('Ask Stacy') }}
    </a>
    <button type="button" class="sidebar__search" x-on:click="$dispatch('open-search')">
        <x-admin.icon name="search" size="15" /><span>{{ __('Search') }}</span><kbd>⌘K</kbd>
    </button>

    @if ($stickers !== [])
        <p class="sidebar__group">{{ __('Chapters') }}</p>
        <nav aria-label="{{ __('Chapters') }}" class="sidebar__list">
            @foreach ($stickers as $item)
                <a href="{{ $item['href'] }}" wire:navigate style="--tone: var(--{{ $item['tone'] }}); --tilt: {{ $loop->odd ? '6deg' : '-6deg' }}" @if ($item['active']) aria-current="page" @endif>
                    <span class="sidebar__dot" aria-hidden="true"></span>
                    <span class="sidebar__label">{{ $item['label'] }}</span>
                    @if ($item['key'] === 'comments' && $waitingComments > 0)
                        <span class="sidebar__badge">{{ $waitingComments }}</span>
                    @elseif ($item['badge'])
                        <span class="sidebar__badge">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
    @endif

    @if ($tools !== [])
        <p class="sidebar__group">{{ __('Tools') }}</p>
        <nav aria-label="{{ __('Tools') }}" class="sidebar__list sidebar__list--tools">
            @foreach ($tools as $item)
                <a href="{{ $item['href'] }}" wire:navigate style="--tone: var(--{{ $item['tone'] }})" @if ($item['active']) aria-current="page" @endif>
                    <span class="sidebar__tab" aria-hidden="true"></span>
                    <span class="sidebar__label">{{ $item['label'] }}</span>
                    @if ($item['badge'])<span class="sidebar__badge">{{ $item['badge'] }}</span>@endif
                </a>
            @endforeach
        </nav>
    @endif

    <a href="{{ route('admin.settings') }}" class="sidebar__settings" wire:navigate @if (request()->routeIs('admin.settings', 'admin.settings.*', 'admin.backups', 'admin.import')) aria-current="page" @endif>
        <x-admin.icon name="settings" size="16" /> {{ __('Settings') }}
    </a>

    {{-- Who's here, and you --}}
    <div class="sidebar__foot">
        @if ($here->isNotEmpty())
            <a href="{{ route('admin.people') }}" class="sidebar__here" wire:navigate>
                <span class="sidebar__faces" aria-hidden="true">
                    @foreach ($here as $person)<x-admin.avatar :user="$person" size="26" presence />@endforeach
                </span>
                <span>{{ trans_choice(':name is here too|:name and :count more are here too', $here->count(), ['name' => str($here->first()->name)->before(' '), 'count' => $here->count() - 1]) }}</span>
            </a>
        @endif

        <div class="sidebar__me" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" x-on:livewire:navigating.window="open = false">
            <div class="sidebar__menu" id="sidebar-menu" x-show="open" x-cloak
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
            <button type="button" class="sidebar__card" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-expanded="false" aria-controls="sidebar-menu">
                <x-admin.avatar :user="$user" size="38" presence />
                <span class="sidebar__who"><span>{{ $user->name }}</span><small>{{ __($user->roleName()) }}</small></span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
            </button>
        </div>
    </div>
</aside>
