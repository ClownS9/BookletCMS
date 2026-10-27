{{-- Search for a site published as plain files: no server to ask, so the reader's browser looks through a small index. --}}
<x-site.layout title="{{ __('Search') }}" robots="noindex, follow">
    <div class="wrap wrap--narrow section">
        <h1 class="sr-only">{{ __('Search') }}</h1>
        <form action="{{ route('site.search') }}" method="GET" role="search" data-static-search data-index="{{ url('search-index.json') }}" data-one="{{ __(':count result for “:q”') }}" data-many="{{ __(':count results for “:q”') }}">
            <label for="q" class="eyebrow">{{ __('Search the site') }}</label>
            <div class="big-search">
                <input id="q" name="q" type="search" placeholder="{{ __('What are you looking for?') }}" autofocus>
                <button type="submit" aria-label="{{ __('Search') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                </button>
            </div>
            <div class="search-bar" hidden data-search-bar><p role="status" data-search-status></p></div>
        </form>

        <ol class="results" data-search-results></ol>
        <div class="note" style="margin-top: 32px" hidden data-search-none>
            <p class="serif" style="font-size: 22px">{{ __('Nothing found.') }}</p>
            <p style="margin-top: 8px">{!! __('Try a shorter word, or browse :blog.', ['blog' => '<a href="'.e($site->blogUrl()).'">'.e(__('the blog')).'</a>']) !!}</p>
        </div>
        <noscript><p class="note" style="margin-top: 32px">{!! __('Search needs JavaScript on this copy of the site. Browse :blog instead.', ['blog' => '<a href="'.e($site->blogUrl()).'">'.e(__('the blog')).'</a>']) !!}</p></noscript>
    </div>

    <script>
        (function () {
            const form = document.querySelector('[data-static-search]');
            const input = form.querySelector('#q');
            const list = document.querySelector('[data-search-results]');
            const none = document.querySelector('[data-search-none]');
            const bar = form.querySelector('[data-search-bar]');
            const status = form.querySelector('[data-search-status]');
            let index = null;
            const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            const run = async (q) => {
                q = q.trim();
                list.innerHTML = '';
                none.hidden = true;
                bar.hidden = q === '';
                if (q === '') return;
                index ??= await fetch(form.dataset.index).then((r) => r.json()).catch(() => []);
                const needle = q.toLowerCase();
                const hits = index
                    .filter((e) => (e.t + ' ' + e.s).toLowerCase().includes(needle))
                    .sort((a, b) => (b.t.toLowerCase().includes(needle)) - (a.t.toLowerCase().includes(needle)))
                    .slice(0, 50);
                status.textContent = (hits.length === 1 ? form.dataset.one : form.dataset.many).replace(':count', hits.length).replace(':q', q);
                none.hidden = hits.length > 0;
                list.innerHTML = hits.map((e, i) => {
                    const at = e.t.toLowerCase().indexOf(needle);
                    const title = at < 0 ? esc(e.t) : esc(e.t.slice(0, at)) + '<mark>' + esc(e.t.slice(at, at + q.length)) + '</mark>' + esc(e.t.slice(at + q.length));
                    return '<li><a href="' + esc(e.u) + '"><span class="results__kind"><span class="sticker" style="--tilt: ' + (i % 2 ? '2deg' : '-2deg') + '; background: ' + (e.p ? 'var(--sun)' : 'var(--mint)') + '">' + esc(e.k) + '</span> ' + esc(e.d) + '</span>'
                        + '<span class="results__title">' + title + '</span><span class="results__excerpt">' + esc(e.x) + '</span></a></li>';
                }).join('');
            };

            const q = new URLSearchParams(location.search).get('q') || '';
            input.value = q;
            run(q);
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                history.replaceState(null, '', '?q=' + encodeURIComponent(input.value));
                run(input.value);
            });
        })();
    </script>
</x-site.layout>
