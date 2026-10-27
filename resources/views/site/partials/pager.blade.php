@if ($paginator->hasPages())
    <nav class="pager" aria-label="{{ __('Pages') }}">
        @if ($paginator->onFirstPage())
            <span class="pager__muted">{{ __('Page :n of :count', ['n' => $paginator->currentPage(), 'count' => $paginator->lastPage()]) }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}">{{ __('← Previous page') }}</a>
        @endif
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}">{{ __('Turn the page →') }}</a>
        @else
            <span class="pager__muted">{{ __('The end of the chapter') }}</span>
        @endif
    </nav>
@endif
