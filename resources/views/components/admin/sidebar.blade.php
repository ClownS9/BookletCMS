{{-- The Dashboard layout's sidebar: your stickers as Content, your tabs as
     Tools, then Settings, each with its pages in a sub-menu; at the foot who
     else is here and your own menu. --}}
@php
    $user = request()->user();
    $side = \App\Support\AdminNav::sidebar($user);
    $waitingComments = collect(\App\Support\AdminNav::waiting())->firstWhere('key', 'comments')['count'] ?? 0;
    $here = rescue(fn () => \App\Models\User::query()->whereKeyNot($user->id)->where('last_seen_at', '>', now()->subMinutes(5))->orderByDesc('last_seen_at')->take(3)->get(), collect(), false);
@endphp
<aside class="sidebar" id="desk-sidebar" aria-label="{{ __('Booklet') }}">
    @foreach (['content' => __('Content'), 'tools' => __('Tools'), 'settings' => __('Back of the book')] as $part => $title)
        @if ($side[$part] !== [])
            <p class="sidebar__group" id="sidebar-{{ $part === 'settings' ? 'back' : $part }}">{{ $title }}</p>
            <nav aria-labelledby="sidebar-{{ $part === 'settings' ? 'back' : $part }}" class="sidebar__list">
                @foreach ($side[$part] as $item)
                    <x-admin.sidebar-item :item="$item" :waiting-comments="$waitingComments" />
                @endforeach
            </nav>
        @endif
    @endforeach

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
            <button type="button" class="sidebar__card" data-tip="{{ $user->name }}" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-expanded="false" aria-controls="sidebar-menu">
                <x-admin.avatar :user="$user" size="38" presence />
                <span class="sidebar__who"><span>{{ $user->name }}</span><small>{{ __($user->roleName()) }}</small></span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
            </button>
        </div>
    </div>
</aside>
