<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Booklet' }} · Booklet</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('booklet/admin.css') }}?v={{ filemtime(public_path('booklet/admin.css')) }}">
    {{-- The book's own scripts, loaded here too: signing in opens the book without a
         page reload, and they must be ready before it arrives (or Stacy's mic and
         the editor's autosave wouldn't start until a refresh). Same files, run once. --}}
    <script src="{{ asset('booklet/password-words.js') }}?v={{ filemtime(public_path('booklet/password-words.js')) }}" data-navigate-once></script>
    <script src="{{ asset('booklet/booklet.js') }}?v={{ filemtime(public_path('booklet/booklet.js')) }}" data-navigate-once></script>
    <script src="{{ asset('booklet/stacy-voice.js') }}?v={{ filemtime(public_path('booklet/stacy-voice.js')) }}" data-navigate-once></script>
    <script src="{{ asset('booklet/stacy-holo.js') }}?v={{ filemtime(public_path('booklet/stacy-holo.js')) }}" data-navigate-once></script>
</head>
<body class="desk desk--center">
    {{ $slot }}

    @if (session('stamp'))
        <div class="toast" role="status">{{ session('stamp') }}</div>
    @endif
</body>
</html>
