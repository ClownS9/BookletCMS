{{-- "Back soon": readers kept away for a while (php booklet down), and the
     moment Booklet swaps its files for a new version. It stands alone, with
     no database or theme, so it shows even mid-update. --}}
@php
    $away = $away ?? null;
    $site = rescue(fn () => \App\Booklet\Facades\Booklet::globals('site')?->get('site_name'), null, false) ?: config('app.name', 'Booklet');
    $message = trim((string) ($away['message'] ?? ''));
    $until = ! empty($away['until']) ? rescue(fn () => \Illuminate\Support\Carbon::parse($away['until'])->timezone(config('app.timezone')), null, false) : null;
    $lang = str_replace('_', '-', app()->getLocale());
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Back soon') }} · {{ $site }}</title>
    <style>
        :root { color-scheme: light dark; --paper: #FBF8F1; --ink: #1E1C18; --muted: #5C564B; --line: #E4DDCE; --sun: #F3C24F; }
        @media (prefers-color-scheme: dark) { :root { --paper: #1B1916; --ink: #EDE6D6; --muted: #C9C0AE; --line: #36322C; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--paper); color: var(--ink); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 460px; text-align: center; }
        .mark { width: 56px; height: 56px; margin: 0 auto 22px; border-radius: 16px; background: var(--sun); display: grid; place-items: center; color: #1E1C18; }
        h1 { margin: 0; font-size: 28px; line-height: 1.2; letter-spacing: -.01em; }
        p { margin: 12px 0 0; color: var(--muted); }
        .note { margin-top: 18px; padding: 12px 16px; border: 1px solid var(--line); border-radius: 12px; color: var(--ink); }
        .site { margin-top: 28px; font-size: 13px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    </style>
</head>
<body>
    <main>
        <div class="mark" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5c3-1.5 6.5-1.5 10 1 3.5-2.5 7-2.5 10-1v14c-3-1.5-6.5-1.5-10 1-3.5-2.5-7-2.5-10-1z"/><path d="M12 6v14"/></svg>
        </div>
        <h1>{{ __('Back soon') }}</h1>
        <p>{{ __('We’re tidying up a few things. Please come back in a little while.') }}</p>
        @if ($message !== '')
            <p class="note">{{ $message }}</p>
        @endif
        @if ($until)
            <p>{{ __('Expected back around :time.', ['time' => $until->translatedFormat('j M, g:i A')]) }}</p>
        @endif
        <p class="site">{{ $site }}</p>
    </main>
</body>
</html>
