@props(['title' => null, 'description' => null, 'image' => null, 'type' => 'website', 'robots' => null])
@php
    $site = app(\App\Booklet\Site::class);
    // Every page shares with a picture: its own, or a card drawn for the site.
    $image ??= \App\Support\ShareImage::siteUrl();
    $drawn = $image && str_contains($image, '/'.\App\Support\ShareImage::FOLDER.'/');
    // Previews of unpublished pages are never for search engines.
    $robots ??= request()->attributes->get('booklet_preview') ? 'noindex, nofollow' : null;
@endphp
<!DOCTYPE html>
<html lang="{{ request()->attributes->get('booklet_lang') ?: str_replace('_', '-', app()->getLocale()) }}" class="no-js"@if (request()->attributes->has('booklet_proof')) data-proof @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script>
        // Before anything paints: pick light or dark, and note that scripts run.
        (function (d) {
            d.classList.remove('no-js'); d.classList.add('js');
            var t = null; try { t = localStorage.getItem('booklet.theme'); } catch (e) {}
            if (!t) t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            d.setAttribute('data-theme', t);
        })(document.documentElement);
    </script>
    <meta name="theme-color" content="#FBF8F1" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#171513" media="(prefers-color-scheme: dark)">
    <title>{{ $title ? $title.' — '.$site->name() : $site->name().($site->tagline() ? ' — '.$site->tagline() : '') }}</title>
    @if ($description) <meta name="description" content="{{ $description }}"> @endif
    @if ($robots) <meta name="robots" content="{{ $robots }}"> @endif
    <meta property="og:site_name" content="{{ $site->name() }}">
    <meta property="og:title" content="{{ $title ?? $site->name() }}">
    @if ($description) <meta property="og:description" content="{{ $description }}"> @endif
    @if ($image)
        <meta property="og:image" content="{{ $site->absolute($image) }}">
        @if ($drawn)
            <meta property="og:image:width" content="{{ \App\Support\ShareImage::WIDTH }}">
            <meta property="og:image:height" content="{{ \App\Support\ShareImage::HEIGHT }}">
            <meta property="og:image:alt" content="{{ $title ?? $site->name() }}">
        @endif
    @endif
    <meta property="og:type" content="{{ $type }}">
    <meta property="og:url" content="{{ $site->absolute(url()->current()) }}">
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
    <link rel="canonical" href="{{ $site->absolute(url()->current()) }}">
    {{-- The same page in other languages, so search engines show each reader their own --}}
    @foreach ((array) request()->attributes->get('booklet_alternates', []) as $version)
        <link rel="alternate" hreflang="{{ $version['code'] }}" href="{{ $version['url'] }}">
        @if ($version['original']) <link rel="alternate" hreflang="x-default" href="{{ $version['url'] }}"> @endif
    @endforeach
    <link rel="alternate" type="application/rss+xml" title="{{ $site->name() }}" href="{{ route('site.feed') }}">
    @if ($site->logoUrl()) <link rel="icon" href="{{ $site->logoUrl() }}"> @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('booklet/site.css') }}?v={{ filemtime(public_path('booklet/site.css')) }}">
    <script src="{{ asset('booklet/site.js') }}?v={{ filemtime(public_path('booklet/site.js')) }}" defer></script>
    <style>:root { --accent: {{ $site->accent() }}; }</style>
    {{-- Inserts that are switched on bring their own styles and scripts --}}
    @foreach (rescue(fn () => app(\App\Inserts\InsertManager::class)->active(), collect(), false) as $insert)
        @foreach ($insert->siteAssets()['css'] as $file) <link rel="stylesheet" href="{{ $insert->asset($file) }}"> @endforeach
        @foreach ($insert->siteAssets()['js'] as $file) <script src="{{ $insert->asset($file) }}" defer></script> @endforeach
    @endforeach
    {{-- What this page is, for search engines (schema.org) --}}
    {{ \App\Support\StructuredData::render() }}
    @livewireStyles
</head>
<body>
    <a href="#main" class="skip">Skip to the words</a>
    <div class="progress" aria-hidden="true"><span></span></div>

    @if ($proof = request()->attributes->get('booklet_proof'))
        <div class="preview-bar proof-bar" role="status">
            <span class="sticker" style="--tilt: -2deg; background: var(--mint)">Proof copy</span>
            <span>
                {{ request()->attributes->get('booklet_preview') === 'published' ? 'This is live already.' : 'Not published yet: only people with this link can see it.' }}
                @if ($proof['by']) Shared by {{ $proof['by'] }}@if ($proof['for']) for {{ $proof['for'] }}@endif. @endif
                <span class="proof-bar__closes">The link closes {{ $proof['closes']->timezone(config('app.timezone'))->format('j M') }}.</span>
            </span>
            @if ($proof['notes'])
                <a href="#proof-notes" class="proof-bar__note" data-proof-open>Leave a note</a>
            @endif
        </div>
    @elseif ($previewing = request()->attributes->get('booklet_preview'))
        <div class="preview-bar" role="status">
            <span class="sticker" style="--tilt: -2deg; background: var(--sun)">Preview</span>
            This is how it will look. It’s {{ $previewing === 'published' ? 'live already' : 'not published yet' }}, so only signed-in people can see this page.
        </div>
    @endif

    <header class="masthead" data-masthead>
        <div class="wrap masthead__inner">
            <a href="{{ url('/') }}" class="brand">
                @if ($site->logoUrl())
                    <img src="{{ $site->logoUrl() }}" alt="" class="brand__logo">
                @else
                    <span class="brand__mark" aria-hidden="true"></span>
                @endif
                <span class="brand__name">{{ $site->name() }}</span>
            </a>

            @php $menu = $site->menu('header'); @endphp
            @if ($menu)
                <nav class="menu" id="site-menu" aria-label="Site">
                    <ul class="menu__list">
                        @foreach ($menu as $n => $item)
                            @php $opens = $item['children'] || $item['mega']; @endphp
                            <li @class(['menu__item', 'menu__item--parent' => $opens, 'menu__item--mega' => $item['mega'], 'menu__item--within' => $item['within'] && ! $item['active']])>
                                <a href="{{ $item['url'] }}" class="menu__link" @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                                @if ($opens)
                                    <button type="button" class="menu__caret" data-menu-sub aria-expanded="false" aria-controls="menu-sub-{{ $n }}">
                                        <span class="sr-only">More under {{ $item['label'] }}</span>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                    </button>
                                    @if ($item['mega'])
                                        <x-site.mega-panel :item="$item" :id="'menu-sub-'.$n" />
                                    @else
                                    <ul class="menu__sub" id="menu-sub-{{ $n }}">
                                        @foreach ($item['children'] as $child)
                                            <li>
                                                <a href="{{ $child['url'] }}" class="menu__sublink" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
                                                @if ($child['children'])
                                                    <ul class="menu__subsub">
                                                        @foreach ($child['children'] as $grand)
                                                            <li><a href="{{ $grand['url'] }}" class="menu__sublink" @if ($grand['active']) aria-current="page" @endif>{{ $grand['label'] }}</a></li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <div class="masthead__tools">
                <a href="{{ route('site.search') }}" class="round-btn" aria-label="Search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                </a>
                <button type="button" class="round-btn theme-toggle" data-theme-toggle aria-label="Switch light or dark">
                    <svg class="theme-toggle__moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>
                    <svg class="theme-toggle__sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
                @if ($menu)
                    <button type="button" class="round-btn menu-toggle" data-menu-toggle aria-controls="site-menu" aria-expanded="false" aria-label="Menu">
                        <span class="menu-toggle__lines" aria-hidden="true"><i></i><i></i><i></i></span>
                    </button>
                @endif
            </div>
        </div>
    </header>

    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    @if (($proof ?? null) && $proof['notes'])
        @include('site.partials.proof-notes', ['proof' => $proof])
    @endif

    <footer class="colophon" id="colophon">
        <div class="wrap colophon__inner">
            <span>© {{ now()->year }} {{ $site->name() }}</span>
            @php $footer = $site->menu('footer'); @endphp
            @if ($footer)
                @if (collect($footer)->contains(fn ($item) => $item['children']))
                    {{-- With groups: a column per top item, its links underneath --}}
                    <nav aria-label="Footer" class="colophon__groups">
                        @foreach ($footer as $item)
                            <div class="colophon__group">
                                <a href="{{ $item['url'] }}" class="colophon__heading">{{ $item['label'] }}</a>
                                @if ($item['children'])
                                    <ul>
                                        @foreach ($item['children'] as $child)
                                            <li><a href="{{ $child['url'] }}">{{ $child['label'] }}</a></li>
                                            @foreach ($child['children'] as $grand)
                                                <li class="colophon__deeper"><a href="{{ $grand['url'] }}">{{ $grand['label'] }}</a></li>
                                            @endforeach
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </nav>
                @else
                    <nav aria-label="Footer" class="colophon__menu">
                        @foreach ($footer as $item)
                            <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                        @endforeach
                    </nav>
                @endif
            @endif
            <span class="colophon__made"><a href="{{ route('site.feed') }}">RSS</a> · made with booklet</span>
        </div>
    </footer>

    <button type="button" class="to-top" data-to-top aria-label="Back to the top">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
    </button>

    {{-- Written into the page (not added afterwards), so kept copies of pages with a form still work. --}}
    @if (\Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest)
        @livewireScripts
    @endif
</body>
</html>
