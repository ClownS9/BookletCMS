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
