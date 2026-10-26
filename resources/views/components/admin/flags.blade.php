@php
    // An insert's page lights the flag of the chapter it's tucked into.
    $insertChapter = request()->routeIs('admin.insert') ? mb_strtolower((string) (\App\Support\AdminNav::spread()['chapterTitle'] ?? '')) : '';
    $flags = [
        ['label' => 'Contents', 'route' => route('admin.contents'), 'tone' => 'coral', 'active' => request()->routeIs('admin.contents', 'admin.activity', 'admin.collections', 'admin.visits', 'admin.bin')],
        request()->user()?->canOpen('posts') === false ? null : ['label' => 'Posts', 'route' => route('admin.entries', 'posts'), 'tone' => 'sun', 'active' => request()->route('collection') === 'posts' || request()->routeIs('admin.calendar', 'admin.taxonomies', 'admin.print') || $insertChapter === 'posts'],
        request()->user()?->canOpen('pages') === false ? null : ['label' => 'Pages', 'route' => route('admin.entries', 'pages'), 'tone' => 'mint', 'active' => request()->route('collection') === 'pages' || request()->routeIs('admin.menus', 'admin.redirects') || $insertChapter === 'pages'],
        ['label' => 'Media', 'route' => route('admin.media'), 'tone' => 'sky', 'active' => request()->routeIs('admin.media') || $insertChapter === 'media'],
        ['label' => 'People', 'route' => route('admin.people'), 'tone' => 'lilac', 'active' => request()->routeIs('admin.people', 'admin.account', 'admin.subscribers') || $insertChapter === 'people'],
        ['label' => 'Comments', 'route' => route('admin.comments'), 'tone' => 'rose', 'active' => request()->routeIs('admin.comments', 'admin.inbox') || $insertChapter === 'comments'],
    ];
    $flags = array_values(array_filter($flags));
@endphp
<nav class="flags" aria-label="{{ __('Sections') }}">
    <a class="flag flag--paper" href="#search" x-on:click.prevent="$dispatch('open-search')" aria-label="{{ __('Search everything') }}">
        <x-admin.icon name="search" size="16" /><span class="kbd-hint">⌘K</span>
    </a>
    <a class="flag flag--ink flag--push" href="{{ route('admin.stacy') }}" wire:navigate @if(request()->routeIs('admin.stacy')) aria-current="page" @endif>
        <x-admin.icon name="stacy" size="16" />{{ __('Ask Stacy') }}
    </a>
    @foreach ($flags as $flag)
        <a class="flag flag--{{ $flag['tone'] }}" href="{{ $flag['route'] }}" wire:navigate @if($flag['active']) aria-current="page" @endif>{{ __($flag['label']) }}</a>
    @endforeach
    <a class="flag flag--sand" href="{{ route('admin.settings') }}" wire:navigate aria-label="{{ __('Settings') }}" @if(request()->routeIs('admin.settings', 'admin.settings.*', 'admin.backups', 'admin.import')) aria-current="page" @endif>
        <x-admin.icon name="settings" />
    </a>
</nav>
