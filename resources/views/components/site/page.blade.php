{{--
    The page itself, for every layout (Booklet's own and a theme's): the <head> with
    its titles, share cards, search-engine notes, fonts, the cover's colours, styles
    and scripts; the preview and proof bars; and, at the end, what forms and proof
    notes need. A theme's layout wraps its header, <main> and footer in this, and
    gets every one of those for free (and whatever Booklet adds to them later).

    <x-site.page :title="$title" :description="$description" :image="$image" :image-alt="$imageAlt" :type="$type" :robots="$robots">
        …header, <main id="main">{{ $slot }}</main>, footer…
    </x-site.page>

    Optional: a <x-slot:head> for more in <head>, and body-class for more classes on <body>.
--}}
@props(['title' => null, 'description' => null, 'image' => null, 'imageAlt' => null, 'type' => 'website', 'robots' => null, 'bodyClass' => '', 'head' => null])
@php
    $site = app(\App\Booklet\Site::class);
    // Every page shares with a picture: its own, or a card drawn for the site.
    $image ??= \App\Support\ShareImage::siteUrl();
    $drawn = $image && str_contains($image, '/'.\App\Support\ShareImage::FOLDER.'/');
    // Previews of unpublished pages are never for search engines.
    $robots ??= request()->attributes->get('booklet_preview') ? 'noindex, nofollow' : null;
    // The cover: fonts, colours and layouts chosen in Settings › The cover.
    $look = \App\Support\Look::current();
    $theme = \App\Themes\ThemeManager::forLook($look);
@endphp
<!DOCTYPE html>
<html lang="{{ request()->attributes->get('booklet_lang') ?: str_replace('_', '-', app()->getLocale()) }}" class="no-js"@if (request()->attributes->has('booklet_proof')) data-proof @endif @if (request()->attributes->get('booklet_static')) data-static @endif>
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
        @elseif ($imageAlt)
            <meta property="og:image:alt" content="{{ $imageAlt }}">
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
    {{-- The fonts don't hold the page up: words show at once in a close system font, then switch
         (a phone on a slow line reads sooner; the fonts' own file is fetched alongside). --}}
    <link rel="preload" as="style" href="{{ \App\Support\Look::fontsUrl($look) }}">
    <link rel="stylesheet" href="{{ \App\Support\Look::fontsUrl($look) }}" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="{{ \App\Support\Look::fontsUrl($look) }}"></noscript>
    @if (! $theme || $theme->usesBookletCss())
        <link rel="stylesheet" href="{{ asset('booklet/site.css') }}?v={{ filemtime(public_path('booklet/site.css')) }}">
    @endif
    {{-- Pages built of sections (and [[builder: …]] in posts) --}}
    <link rel="stylesheet" href="{{ asset('booklet/builder.css') }}?v={{ filemtime(public_path('booklet/builder.css')) }}">
    <script src="{{ asset('booklet/site.js') }}?v={{ filemtime(public_path('booklet/site.js')) }}" defer></script>
    {{-- A theme's own styles (and, for a theme with code, its scripts) come after Booklet's --}}
    @if ($theme)
        @foreach ($theme->css() as $file) <link rel="stylesheet" href="{{ $theme->asset($file) }}"> @endforeach
        @foreach ($theme->js() as $file) <script src="{{ $theme->asset($file) }}" defer></script> @endforeach
    @endif
    <style>{!! \App\Support\Look::css($look) !!}</style>
    {{-- Inserts that are switched on bring their own styles and scripts --}}
    @foreach (rescue(fn () => app(\App\Inserts\InsertManager::class)->active(), collect(), false) as $insert)
        @foreach ($insert->siteAssets()['css'] as $file) <link rel="stylesheet" href="{{ $insert->asset($file) }}"> @endforeach
        @foreach ($insert->siteAssets()['js'] as $file) <script src="{{ $insert->asset($file) }}" defer></script> @endforeach
    @endforeach
    {{-- What this page is, for search engines (schema.org) --}}
    {{ \App\Support\StructuredData::render() }}
    @livewireStyles
    {{ $head }}
</head>
<body class="{{ trim(\App\Support\Look::bodyClasses($look).' '.$bodyClass) }}">
    <a href="#main" class="skip">{{ __('Skip to the words') }}</a>
    <div class="progress" aria-hidden="true"><span></span></div>

    @if ($proof = request()->attributes->get('booklet_proof'))
        <div class="preview-bar proof-bar" role="status">
            <span class="sticker" style="--tilt: -2deg; background: var(--mint)">{{ __('Proof copy') }}</span>
            <span>
                {{ request()->attributes->get('booklet_preview') === 'published' ? __('This is live already.') : __('Not published yet: only people with this link can see it.') }}
                @if ($proof['by']) {{ $proof['for'] ? __('Shared by :name for :for.', ['name' => $proof['by'], 'for' => $proof['for']]) : __('Shared by :name.', ['name' => $proof['by']]) }} @endif
                <span class="proof-bar__closes">{{ __('The link closes :date.', ['date' => $proof['closes']->timezone(config('app.timezone'))->translatedFormat('j M')]) }}</span>
            </span>
            @if ($proof['notes'])
                <a href="#proof-notes" class="proof-bar__note" data-proof-open>{{ __('Leave a note') }}</a>
            @endif
        </div>
    @elseif ($previewing = request()->attributes->get('booklet_preview'))
        <div class="preview-bar" role="status">
            <span class="sticker" style="--tilt: -2deg; background: var(--sun)">{{ __('Preview') }}</span>
            This is how it will look. It’s {{ $previewing === 'published' ? 'live already' : 'not published yet' }}, so only signed-in people can see this page.
        </div>
    @endif

    {{ $slot }}

    @if (($proof ?? null) && $proof['notes'])
        @include('site.partials.proof-notes', ['proof' => $proof])
    @endif

    <button type="button" class="to-top" data-to-top aria-label="{{ __('Back to the top') }}">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
    </button>

    {{-- Written into the page (not added afterwards), so kept copies of pages with a form still work. --}}
    @if (\Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest)
        @livewireScripts
    @endif
</body>
</html>
