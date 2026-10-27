{{-- Installing stopped while making the tables: what the database said, and a way to try again. Plain: no sessions yet. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Install · Booklet</title>
    <link rel="stylesheet" href="{{ asset('booklet/admin.css') }}">
</head>
<body class="desk desk--center">
<main class="setup">
    <div class="book setup__book">
        <div class="book__pages">
            <section class="page page--right" style="--ribbon: var(--coral)">
                <div class="ribbon" aria-hidden="true"></div>
                <p class="eyebrow">Install Booklet</p>
                <h1 class="heading" style="margin-top: 12px">The tables couldn’t be made</h1>
                <p class="lede" style="margin-top: 12px">The settings were saved, but the database said:</p>
                <pre style="white-space: pre-wrap; font-family: var(--mono); font-size: 13px; padding: 12px 14px; border-radius: 10px; background: var(--paper-3); border: 1px solid var(--rule-2)">{{ $problem }}</pre>
                <p class="lede">Fix that in your hosting panel (often the database user needs all permissions on the database), then try again. The link works for an hour.</p>
                <p style="margin-top: 18px"><a class="btn btn--ink" href="{{ $retry }}">Try again</a></p>
            </section>
        </div>
    </div>
</main>
</body>
</html>
