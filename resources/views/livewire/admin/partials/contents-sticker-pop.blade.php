{{-- A new sticker: slapped onto the page once, then kept in the sticker book --}}
@if ($celebrate)
    <div class="sticker-pop" x-data="bookletStickerPop()" x-show="open" x-cloak
         x-on:keydown.escape.window="close()" x-on:click.self="close()"
         role="dialog" aria-modal="true" aria-labelledby="sticker-pop-title">
        <div class="sticker-pop__card" x-trap.noscroll="open">
            <div class="sticker-pop__spot" aria-hidden="true">
                <span class="sticker-pop__burst"></span>
                <x-admin.milestone :s="$celebrate" size="lg" class="sticker-pop__sticker" />
            </div>
            <p class="eyebrow">{{ __('New sticker') }} @if ($alsoEarned) · {{ __('and :count more', ['count' => $alsoEarned]) }} @endif</p>
            <h2 id="sticker-pop-title" class="serif sticker-pop__title">{{ $celebrate['title'] }}</h2>
            <p class="sticker-pop__line">{{ __($celebrate['line']) }}</p>
            <p class="sticker-pop__when mono">{{ $celebrate['scope'] === 'book' ? __('For the whole book') : __('Yours') }} · {{ $celebrate['at'] }}</p>
            <div class="sticker-pop__do">
                <button type="button" class="btn btn--ink" x-on:click="close()">{{ __('Stick it in the book') }}</button>
                <a href="{{ route('admin.account') }}#stickers" class="btn btn--quiet" wire:navigate>{{ __('See them all') }}</a>
            </div>
        </div>
    </div>
@endif
