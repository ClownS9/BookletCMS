@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="pager__muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}">← Previous page</a>
        @endif
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}">Turn the page →</a>
        @else
            <span class="pager__muted">The end of the chapter</span>
        @endif
    </nav>
@endif
