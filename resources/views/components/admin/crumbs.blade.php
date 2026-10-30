{{-- Where you are, above a page's title. The Book layout shows only the page's
     own back link; the Dashboard layout starts the trail at Dashboard. --}}
@if (\App\Support\YourBook::desk())
    <nav class="page-head__crumbs" aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('admin.contents') }}" wire:navigate>{{ __('Dashboard') }}</a>
        {{ $slot }}
    </nav>
@else
    {{ $slot }}
@endif
