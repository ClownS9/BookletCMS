@props(['title', 'close' => 'closeDrawer', 'label' => null])
{{-- A panel that slides in from the right over a Dashboard table: one row's
     details, opened from the table and closed with ×, Esc or a click beside it. --}}
@teleport('body')
<div class="drawer" x-data x-on:keydown.escape.window="$wire.{{ $close }}()" wire:key="drawer">
    <div class="drawer__shade" x-on:click="$wire.{{ $close }}()" aria-hidden="true"></div>
    <aside {{ $attributes->class('drawer__panel') }} role="dialog" aria-modal="true" aria-labelledby="drawer-title" x-trap.noscroll="true">
        <header class="drawer__head">
            <h2 id="drawer-title">{{ $title }}</h2>
            <button type="button" class="icon-btn" wire:click="{{ $close }}" aria-label="{{ __('Close') }}" title="{{ __('Close (Esc)') }}"><x-admin.icon name="close" size="18" /></button>
        </header>
        <div class="drawer__body">
            {{ $slot }}
        </div>
        @isset($foot)
            <footer class="drawer__foot">{{ $foot }}</footer>
        @endisset
    </aside>
</div>
@endteleport
