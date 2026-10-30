<div style="display: contents">
    {{-- Left page: what this insert is, and anything it shows there --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <div class="page-head">
                <x-admin.crumbs />
                <p class="eyebrow chapter-mark">{{ __(':value · an insert', ['value' => \App\Support\AdminNav::chapterLabel()]) }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ $page['title'] }}</h1>
            </div>
            @if ($insert->about() !== '')
                <p class="lede" style="margin-top: 12px; font-size: 15px">{{ $insert->about() }}</p>
            @endif
        </div>

        @if ($page['left'] && $insert->hasView($page['left']))
            {{ $insert->view($page['left'], $data) }}
        @endif

        @if ($insert->slipHelp() !== [])
            <p class="field__hint" style="margin-top: auto">In a post or page:
                @foreach ($insert->slipHelp() as $line) <code>{{ __($line) }}</code>@if (! $loop->last), @endif @endforeach
            </p>
        @endif
        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the insert's own view --}}
    <section class="page page--right page--stack" style="--ribbon: var(--{{ $insert->sticker() }})">
        <div class="ribbon" aria-hidden="true"></div>
        <div class="insert-page">
            @if ($insert->hasView($page['view']))
                {{ $insert->view($page['view'], $data) }}
            @else
                <p class="empty-note">This insert has no {{ $page['view'] }} view yet (inserts/{{ $insert->key }}/views/{{ $page['view'] }}.blade.php).</p>
            @endif
        </div>
        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
