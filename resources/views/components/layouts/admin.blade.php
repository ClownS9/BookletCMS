<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#23211D">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? __($title).' · ' : '' }}Booklet</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('booklet/admin.css') }}?v={{ filemtime(public_path('booklet/admin.css')) }}">
    <script src="{{ asset('booklet/password-words.js') }}?v={{ filemtime(public_path('booklet/password-words.js')) }}" data-navigate-once></script>
    <script src="{{ asset('booklet/booklet.js') }}?v={{ filemtime(public_path('booklet/booklet.js')) }}" data-navigate-once></script>
    <script src="{{ asset('booklet/stacy-voice.js') }}?v={{ filemtime(public_path('booklet/stacy-voice.js')) }}" data-navigate-once></script>
    <script src="{{ asset('booklet/stacy-holo.js') }}?v={{ filemtime(public_path('booklet/stacy-holo.js')) }}" data-navigate-once></script>
    <script src="{{ asset('booklet/vendor/qrcode.js') }}" defer data-navigate-once></script>
</head>
<body @class(['night' => (request()->user()?->preferences['night'] ?? false)])
      data-page-sound="{{ (request()->user()?->preferences['page_sound'] ?? true) ? 'on' : 'off' }}"
      x-data
>
    <a href="#main" class="skip">{{ __('Skip to the page') }}</a>
    <x-admin.handheld-nav />

    {{-- The desk lamp for night reading (kept across page changes, so its cord keeps swinging) --}}
    @persist('lamp')
        <x-admin.lamp />
    @endpersist

    @if ($as = request()->user()?->viewingAs())
        {{-- Trying the book out as another role --}}
        <form method="POST" action="{{ route('admin.view-as.stop') }}" class="viewing-as" role="status">
            @csrf
            <span>{!! __('Seeing the book as :role. Only you see this.', ['role' => '<strong>'.e(\App\Models\Role::choices()[$as]['name'] ?? $as).'</strong>']) !!}</span>
            <button type="submit" class="btn btn--sm btn--ink">{{ __('Back to admin') }}</button>
        </form>
    @endif

    <div class="desk">
        <x-admin.flags />

        <div class="book" data-position="{{ \App\Support\AdminNav::position() }}">
            <x-admin.thumbs />
            <main class="book__pages" id="main" tabindex="-1">
                {{ $slot }}
            </main>
            {{-- Fold the corner to bookmark this page --}}
            <livewire:admin.dog-ear :key="'dog-ear-'.md5(request()->getRequestUri())" />
        </div>

        <footer class="desk__footer">
            <span>{{ __('Signed in as :name', ['name' => request()->user()->name]) }}</span>
            <button type="button" class="link-button desk__keys" x-on:click="$dispatch('open-shortcuts')"><kbd class="kbd">?</kbd> {{ __('Shortcuts') }}</button>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="link-button"><x-admin.icon name="logout" size="15" /> {{ __('Sign out') }}</button>
            </form>
        </footer>
    </div>

    {{-- Keyboard shortcuts (press ?) --}}
    <x-admin.shortcuts />

    {{-- Search everything: a window over the book (⌘K, Ctrl+K, "/") --}}
    @persist('search')
        <livewire:admin.search-palette />
    @endpersist

    @unless (request()->routeIs('admin.stacy'))
        <livewire:admin.stacy-orb />
    @endunless

    {{-- The stamp ("Saved.", "Moved to the bin."), with Undo when something went in the bin --}}
    <div x-data="bookletStamp(@js(session('stamp')), @js(session('stamp_undo')), @js(url('/admin/bin')))"
         x-on:stamp.window="pop($event.detail.text, $event.detail.undo)">
        {{-- Always on the page (empty until a stamp), so screen readers announce it reliably. --}}
        <p class="sr-only" role="status" aria-live="polite" x-text="show ? text : ''"></p>
        <div class="toast" x-show="show" x-cloak @click="!undo && (show = false)"
             x-on:mouseenter="hold()" x-on:mouseleave="release()"
             x-transition:leave="toast-out" x-transition:leave-end="toast-out--to">
            <span x-text="text"></span>
            <button type="button" class="toast__undo" x-show="undo" x-on:click.stop="putBack()" :disabled="busy">{{ __('Undo') }}</button>
        </div>
    </div>
</body>
</html>
