{{-- The writing desk's formatting buttons (and Stacy's pen) --}}
@if ($bodyField)
    <div class="format-bar" role="toolbar" aria-label="{{ __('Formatting') }}">
        <button type="button" x-on:click="format('heading')" title="{{ __('Heading') }}" aria-label="{{ __('Heading') }}"><span class="serif" style="font-weight: 600">H</span></button>
        <button type="button" x-on:click="format('bold')" title="{{ __('Bold (Ctrl/⌘ B)') }}" aria-label="{{ __('Bold') }}"><strong>B</strong></button>
        <button type="button" x-on:click="format('italic')" title="{{ __('Italic (Ctrl/⌘ I)') }}" aria-label="{{ __('Italic') }}"><em class="serif">I</em></button>
        <button type="button" x-on:click="format('link')" title="{{ __('Link (Ctrl/⌘ K)') }}" aria-label="{{ __('Link') }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>
        </button>
        <button type="button" x-on:click="format('list')" title="{{ __('List') }}" aria-label="{{ __('List') }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg>
        </button>
        <button type="button" x-on:click="format('quote')" title="{{ __('Quote') }}" aria-label="{{ __('Quote') }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M7 7h4v4c0 3-2 5-4 6M15 7h4v4c0 3-2 5-4 6"/></svg>
        </button>
        @if ($stacyHelp)
            <button type="button" class="format-bar__stacy" x-on:click="pen()" :aria-expanded="help.open ? 'true' : 'false'"
                    title="{{ __('Ask Stacy to help with the words (Ctrl/⌘ J)') }}" aria-label="{{ __('Ask Stacy to help with the words') }}">
                <x-admin.icon name="stacy" size="15" /> <span>{{ __('Stacy') }}</span>
            </button>
        @endif
    </div>
@endif
