@props(['item', 'waitingComments' => 0])
{{-- One item in the Dashboard's sidebar, and the pages that open under it.
     Open: a chevron folds the sub-menu (remembered in this browser). Folded
     to the rail: the sub-menu flies out beside the icon. --}}
@php
    $subs = $item['subs'] ?? [];
    $childHere = collect($subs)->contains('active', true);
    $here = $item['active'] && ! $childHere;
    $id = 'sub-'.$item['key'];
    $badge = $item['key'] === 'comments' && $waitingComments > 0 ? $waitingComments : $item['badge'];
@endphp
<div @class(['sidebar__item', 'has-sub' => $subs !== [], 'has-current' => $childHere])
     @if ($subs !== []) x-data="bookletSideSub(@js($item['key']), @js((bool) $item['open']))" x-on:mouseenter="fly($el)" x-on:mouseleave="land()" x-on:focusin="fly($el)" x-on:focusout="$el.contains($event.relatedTarget) || land()" x-on:keydown.escape="land()" :class="{ 'is-open': open, 'is-flying': flying }" @endif
     style="--tone: var(--{{ $item['tone'] }})">
    <span class="sidebar__row">
        <a href="{{ $item['href'] }}" wire:navigate @if ($subs === []) data-tip="{{ $item['label'] }}" @endif @if ($here) aria-current="page" @endif>
            @if ($item['key'] === 'settings')
                <x-admin.icon name="settings" size="15" class="sidebar__gear" />
            @else
                <span class="sidebar__dot" aria-hidden="true"></span>
            @endif
            <span class="sidebar__icon" aria-hidden="true"><x-admin.icon :name="$item['icon']" size="16" /></span>
            <span class="sidebar__label">{{ $item['label'] }}</span>
            @if ($badge)
                <span class="sidebar__badge">{{ $badge }}@if ($item['key'] === 'comments')<span class="sr-only"> {{ __('waiting') }}</span>@endif</span>
            @endif
        </a>
        @if ($subs !== [])
            <button type="button" class="sidebar__more" x-on:click="toggle()" :aria-expanded="open.toString()" aria-expanded="{{ $item['open'] ? 'true' : 'false' }}" aria-controls="{{ $id }}"
                    aria-label="{{ __('More in :name', ['name' => $item['label']]) }}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </button>
        @endif
    </span>
    @if ($subs !== [])
        <ul class="sidebar__sub" id="{{ $id }}" role="list">
            <li class="sidebar__flyhead"><a href="{{ $item['href'] }}" wire:navigate @if ($here) aria-current="page" @endif>{{ $item['label'] }}</a></li>
            @foreach ($subs as $sub)
                <li>
                    <a href="{{ $sub['href'] }}" wire:navigate @if ($sub['active']) aria-current="page" @endif>
                        <span class="sidebar__label">{{ $sub['label'] }}</span>
                        @if ($sub['badge'])<span class="sidebar__badge">{{ $sub['badge'] }}</span>@endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
