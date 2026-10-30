@php
    // An insert's page lights the flag of the chapter it's tucked into.
    $insertChapter = request()->routeIs('admin.insert') ? mb_strtolower((string) (\App\Support\AdminNav::spread()['chapterTitle'] ?? '')) : '';
    $flags = [
        ['key' => 'contents', 'label' => 'Contents', 'route' => route('admin.contents'), 'tone' => 'coral', 'active' => request()->routeIs('admin.contents', 'admin.activity', 'admin.collections', 'admin.visits', 'admin.bin')],
        request()->user()?->canOpen('posts') === false ? null : ['key' => 'posts', 'label' => 'Posts', 'route' => route('admin.entries', 'posts'), 'tone' => 'sun', 'active' => request()->route('collection') === 'posts' || request()->routeIs('admin.calendar', 'admin.taxonomies', 'admin.print') || $insertChapter === 'posts'],
        request()->user()?->canOpen('pages') === false ? null : ['key' => 'pages', 'label' => 'Pages', 'route' => route('admin.entries', 'pages'), 'tone' => 'mint', 'active' => request()->route('collection') === 'pages' || request()->routeIs('admin.menus', 'admin.redirects') || $insertChapter === 'pages'],
        ['key' => 'media', 'label' => 'Media', 'route' => route('admin.media'), 'tone' => 'sky', 'active' => request()->routeIs('admin.media') || $insertChapter === 'media'],
        ['key' => 'people', 'label' => 'People', 'route' => route('admin.people'), 'tone' => 'lilac', 'active' => request()->routeIs('admin.people', 'admin.account', 'admin.subscribers', 'admin.readers', 'admin.readers.*') || $insertChapter === 'people'],
        ['key' => 'comments', 'label' => 'Comments', 'route' => route('admin.comments'), 'tone' => 'rose', 'active' => request()->routeIs('admin.comments', 'admin.inbox') || $insertChapter === 'comments'],
    ];
    $flags = array_values(array_filter($flags));
    $notes = \App\Support\AdminNav::notes();
    $back = \App\Support\AdminNav::backOfTheBook();
    $waitingComments = collect(\App\Support\AdminNav::waiting())->firstWhere('key', 'comments')['count'] ?? 0;
@endphp
{{-- The stickers. A chapter with pages of its own has a little fold-out: press
     the arrow for a sticky note of its pages, counts and a New button. --}}
<nav class="flags" aria-label="{{ __('Sections') }}"
     x-data="{ open: null }"
     x-on:keydown.escape.window="if (open) { const was = open; open = null; $nextTick(() => $refs['more-' + was]?.focus()) }"
     x-on:click.outside="open = null"
     x-on:livewire:navigating.window="open = null">
    <a class="flag flag--paper" href="#search" x-on:click.prevent="$dispatch('open-search')" aria-label="{{ __('Search everything') }}">
        <x-admin.icon name="search" size="16" /><span class="kbd-hint">⌘K</span>
    </a>
    <a class="flag flag--ink flag--push" href="{{ route('admin.stacy') }}" wire:navigate @if(request()->routeIs('admin.stacy')) aria-current="page" @endif>
        <x-admin.icon name="stacy" size="16" />{{ __('Ask Stacy') }}
    </a>
    @foreach ($flags as $flag)
        @php $note = $notes[$flag['key']] ?? null; @endphp
        @if ($note)
            <span class="flag-pair flag--{{ $flag['tone'] }}" :class="open === '{{ $flag['key'] }}' && 'is-open'" @if ($flag['active']) data-current @endif>
                <a class="flag flag--{{ $flag['tone'] }} flag-pair__go" href="{{ $flag['route'] }}" wire:navigate @if($flag['active']) aria-current="page" @endif>{{ __($flag['label']) }}</a>
                <button type="button" class="flag flag--{{ $flag['tone'] }} flag-pair__more" x-ref="more-{{ $flag['key'] }}"
                        x-on:click="open = open === '{{ $flag['key'] }}' ? null : '{{ $flag['key'] }}'"
                        :aria-expanded="(open === '{{ $flag['key'] }}').toString()" aria-expanded="false" aria-controls="flag-note-{{ $flag['key'] }}"
                        aria-label="{{ __('What’s in :chapter', ['chapter' => __($flag['label'])]) }}">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="flag-note" id="flag-note-{{ $flag['key'] }}" x-show="open === '{{ $flag['key'] }}'" x-cloak
                     x-transition:enter="flag-note-in" x-transition:enter-start="flag-note-in--from" x-transition:leave="flag-note-out" x-transition:leave-end="flag-note-in--from">
                    <p class="flag-note__eyebrow">{{ $note['title'] }}</p>
                    <ul class="flag-note__list">
                        @foreach ($note['items'] as $item)
                            <li><a href="{{ $item['href'] }}" wire:navigate @if ($item['active']) aria-current="page" @endif><span>{{ $item['label'] }}</span>@if ($item['count'] !== null)<span class="flag-note__n">{{ number_format($item['count']) }}</span>@endif</a></li>
                        @endforeach
                    </ul>
                    @if ($note['more'] !== [])
                        <p class="flag-note__more">
                            @foreach ($note['more'] as $more)
                                <a href="{{ $more['href'] }}" wire:navigate @if ($more['active']) aria-current="page" @endif>{{ $more['label'] }}</a>
                            @endforeach
                        </p>
                    @endif
                    @if ($note['new'])
                        <a href="{{ $note['new']['href'] }}" class="flag-note__new" wire:navigate><x-admin.icon name="plus" size="15" /> {{ $note['new']['label'] }}</a>
                    @endif
                </div>
            </span>
        @else
            <a class="flag flag--{{ $flag['tone'] }}" href="{{ $flag['route'] }}" wire:navigate @if($flag['active']) aria-current="page" @endif>
                {{ __($flag['label']) }}
                @if ($flag['key'] === 'comments' && $waitingComments > 0)
                    <span class="flag__badge" aria-label="{{ trans_choice(':count waiting|:count waiting', $waitingComments) }}">{{ $waitingComments }}</span>
                @endif
            </a>
        @endif
    @endforeach
    <span class="flag-pair flag-pair--gear" :class="open === 'gear' && 'is-open'" @if (request()->routeIs('admin.settings', 'admin.settings.*', 'admin.backups', 'admin.import', 'admin.account')) data-current @endif>
        <button type="button" class="flag flag--sand" x-ref="more-gear"
                x-on:click="open = open === 'gear' ? null : 'gear'"
                :aria-expanded="(open === 'gear').toString()" aria-expanded="false" aria-controls="flag-note-gear"
                aria-label="{{ __('Settings and the back of the book') }}"
                @if (request()->routeIs('admin.settings', 'admin.settings.*', 'admin.backups', 'admin.import', 'admin.account')) aria-current="page" @endif>
            <x-admin.icon name="settings" />
        </button>
        <div class="flag-note flag-note--wide" id="flag-note-gear" x-show="open === 'gear'" x-cloak
             x-transition:enter="flag-note-in" x-transition:enter-start="flag-note-in--from" x-transition:leave="flag-note-out" x-transition:leave-end="flag-note-in--from">
            <p class="flag-note__eyebrow">{{ __('The back of the book') }}</p>
            <ul class="flag-note__grid">
                @foreach ($back as $item)
                    <li><a href="{{ $item['href'] }}" wire:navigate @if ($item['active']) aria-current="page" @endif><span>{{ $item['label'] }}</span><small>{{ $item['hint'] }}</small></a></li>
                @endforeach
            </ul>
        </div>
    </span>
</nav>
