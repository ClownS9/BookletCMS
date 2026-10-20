<x-site.layout title="Page not found">
    <div class="torn-wrap">
        <div class="torn">
            <span class="sticker torn__sticker" style="--tilt: 6deg; background: var(--rose)">Missing</span>
            <p class="torn__number" aria-hidden="true">404</p>
            <h1>This page has been torn out</h1>
            <p>It may have moved, or the link has a typo. Try searching, or head back to the start of the book.</p>
            <div class="torn__actions">
                <a href="{{ url('/') }}" class="button button--ink">Back to the start</a>
                <a href="{{ route('site.search') }}" class="button">Search the site</a>
            </div>
        </div>
    </div>
</x-site.layout>
