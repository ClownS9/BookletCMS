{{-- Every email from the book, signed with the site's own name (not the framework's). --}}
@php
    $siteName = rescue(fn () => app(\App\Booklet\Site::class)->name(), config('app.name'), false);
    $siteUrl = rescue(fn () => app(\App\Booklet\Site::class)->absolute(url('/')), config('app.url'), false);
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$siteUrl">
{{ $siteName }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ $siteName }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
