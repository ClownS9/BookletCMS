{{-- Where you are, above a page's title. The Book layout shows only the page's
     own back link; the Dashboard layout starts the trail at Dashboard and ends
     it with the section you're in, which opens the pages beside it (⇕). --}}
@if (\App\Support\YourBook::desk())
    @php
        $switch = \App\Support\AdminNav::switcher();
        $trail = (string) $slot;
        if ($switch) {
            // Links to the section, to a page beside this one, or back to the
            // Dashboard are in the switcher already, so they aren't shown twice.
            $known = collect($switch['groups'])->flatMap(fn ($g) => array_column($g['items'], 'href'))
                ->push($switch['href'], route('admin.contents'))
                ->map(fn ($h) => strtok($h, '?#'))->unique()->all();
            $trail = (string) preg_replace_callback('#<a\b[^>]*\bhref="([^"]*)"[^>]*>.*?</a>#s',
                fn ($m) => in_array(strtok(html_entity_decode($m[1]), '?#'), $known, true) ? '' : $m[0], $trail);
        }
    @endphp
    <nav class="page-head__crumbs" aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('admin.contents') }}" wire:navigate>{{ __('Dashboard') }}</a>
        {!! $trail !!}
        @if ($switch)
            <x-admin.crumb-switch :switch="$switch" />
        @endif
    </nav>
@else
    {{ $slot }}
@endif
