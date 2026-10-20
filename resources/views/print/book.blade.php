<!DOCTYPE html>
<html lang="{{ $o['lang'] ?: str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $o['title'] }} · Print</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400;1,6..72,500&family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('booklet/print.css') }}?v={{ filemtime(public_path('booklet/print.css')) }}">
    <style>
        @page { size: {{ $width }} {{ $height }}; margin: 0; }
        :root { --pw: {{ $width }}; --ph: {{ $height }}; --accent: {{ $site->accent() }}; }
    </style>
    <script src="{{ asset('booklet/print.js') }}?v={{ filemtime(public_path('booklet/print.js')) }}" defer></script>
</head>
<body class="size-{{ $o['size'] }}" data-recto="{{ $o['recto'] ? '1' : '0' }}" data-title="{{ $o['title'] }}">
    <header class="press">
        <a href="{{ $back }}" class="press__back">← Back to Booklet</a>
        <span class="press__what">
            <strong>{{ $o['title'] }}</strong>
            <span class="press__status" data-status>Setting the type…</span>
        </span>
        <span class="press__do">
            <span class="press__tip">In the print window, choose <b>Save as PDF</b> to keep a file.</span>
            <button type="button" class="press__print" data-print disabled>Print or save as PDF</button>
        </span>
    </header>

    <main class="desk-print">
        <div class="spreads" data-pages aria-label="The book, page by page"></div>
        <noscript><p class="press__noscript">The pages are cut in your browser, so this needs JavaScript.</p></noscript>
    </main>

    {{-- Everything in reading order, one long column; print.js pours it into pages --}}
    <div class="source" data-source aria-hidden="true">
        <section data-kind="title">
            <div class="title-page">
                @if ($site->logoUrl()) <img class="title-page__logo" src="{{ $site->logoUrl() }}" alt=""> @endif
                <h1 class="title-page__title">{{ $o['title'] }}</h1>
                @if ($o['subtitle']) <p class="title-page__sub">{{ $o['subtitle'] }}</p> @endif
                <span class="title-page__rule"></span>
                @if ($byline) <p class="title-page__by">{{ $byline }}</p> @endif
                <p class="title-page__imprint">{{ $site->name() }}</p>
            </div>
        </section>

        <section data-kind="colophon">
            <div class="colophon">
                <p>{{ $o['title'] }}@if ($o['subtitle']): {{ $o['subtitle'] }}@endif</p>
                <p>© {{ $years->isEmpty() ? now()->year : ($years->first() === $years->last() ? $years->first() : $years->first().'–'.$years->last()) }} {{ $byline ?: $site->name() }}. All rights reserved.</p>
                <p>First published at {{ preg_replace('#^https?://#', '', rtrim($site->absolute('/'), '/')) }}.</p>
                <p>{{ number_format($entries->count()) }} {{ str('piece')->plural($entries->count()) }}, set in Newsreader and printed {{ now()->format('j F Y') }} with Booklet.</p>
            </div>
        </section>

        @if ($o['toc'] && $entries->isNotEmpty())
            <section data-kind="contents">
                <h2 class="toc-head">Contents</h2>
                <ol class="toc">
                    @foreach ($entries as $i => $entry)
                        <li data-target="ch-{{ $i }}">
                            <span class="toc__title">{{ $entry->title() ?: 'Untitled' }}</span>
                            <span class="toc__lead"></span>
                            <span class="toc__pg" data-pg></span>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        @forelse ($entries as $i => $entry)
            @php
                $author = $site->author($entry);
                $cover = $o['covers'] ? $site->image($entry) : null;
            @endphp
            <article data-kind="chapter" id="ch-{{ $i }}" data-title="{{ $entry->title() ?: 'Untitled' }}">
                <header class="opening">
                    @if ($entry->date()) <p class="opening__date">{{ $entry->date()->format('j F Y') }}</p> @endif
                    <h2 class="opening__title">{{ $entry->title() ?: 'Untitled' }}</h2>
                    @if ($entry->get('excerpt')) <p class="opening__standfirst">{{ $entry->get('excerpt') }}</p> @endif
                    @if ($author && ! $o['author'] && $byline !== ($author->byline ?: $author->name))
                        <p class="opening__by">{{ $author->byline ?: $author->name }}</p>
                    @endif
                </header>
                @if ($cover)
                    <figure class="plate">
                        <img src="{{ $cover }}" alt="{{ $site->imageAlt($entry) }}" style="object-position: {{ $site->imageFocus($entry) }}">
                        @if ($entry->get('cover_caption')) <figcaption>{{ $entry->get('cover_caption') }}</figcaption> @endif
                    </figure>
                @endif
                <div class="prose">{{ $site->markdown($entry->get('content')) }}</div>
                @if ($loop->last) <p class="fin" aria-label="The end">❦</p> @endif
            </article>
        @empty
            <article data-kind="chapter" id="ch-0" data-title="">
                <div class="prose"><p>Nothing published matches these choices yet.</p></div>
            </article>
        @endforelse
    </div>
</body>
</html>
