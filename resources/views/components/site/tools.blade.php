{{--
    The round buttons at the top: search, light or dark, and (when there's a menu) the
    menu button for phones. <x-site.tools /> or <x-site.tools :menu-button="false" />.
--}}
@props(['menuButton' => null])
@php $menuButton ??= (bool) app(\App\Booklet\Site::class)->menu('header'); @endphp
<div class="masthead__tools">
    <a href="{{ route('site.search') }}" class="round-btn" aria-label="{{ __('Search') }}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
    </a>
    @if (\App\Support\Readers::showLink())
        {{-- Readers: sign in, or their own page --}}
        @php $me = \App\Support\Readers::signedIn(); @endphp
        <a href="{{ $me ? route('readers.home') : route('readers.sign-in') }}" @class(['round-btn', 'reader-btn', 'reader-btn--in' => $me]) aria-label="{{ $me ? __('Your account') : __('Sign in') }}" title="{{ $me ? $me->name : __('Sign in') }}">
            @if ($me)
                <span class="reader-btn__initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($me->name, 0, 1)) }}</span>
            @else
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            @endif
        </a>
    @endif
    <button type="button" class="round-btn theme-toggle" data-theme-toggle aria-label="{{ __('Switch light or dark') }}">
        <svg class="theme-toggle__moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>
        <svg class="theme-toggle__sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    </button>
    @if ($menuButton)
        <button type="button" class="round-btn menu-toggle" data-menu-toggle aria-controls="site-menu" aria-expanded="false" aria-label="{{ __('Menu') }}">
            <span class="menu-toggle__lines" aria-hidden="true"><i></i><i></i><i></i></span>
        </button>
    @endif
</div>
