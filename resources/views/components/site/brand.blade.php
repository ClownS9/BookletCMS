{{--
    The site's name, with its logo (or Booklet's little mark), linking home; the
    tagline too under the Banner header. <x-site.brand /> or <x-site.brand :tagline="true" />.
--}}
@props(['tagline' => null])
@php
    $site = app(\App\Booklet\Site::class);
    $tagline ??= \App\Support\Look::current()['header'] === 'banner';
@endphp
<a href="{{ url('/') }}" {{ $attributes->merge(['class' => 'brand']) }}>
    @if ($site->logoUrl())
        <img src="{{ $site->logoUrl() }}" alt="" class="brand__logo">
    @else
        <span class="brand__mark" aria-hidden="true"></span>
    @endif
    <span class="brand__name">{{ $site->name() }}</span>
    @if ($tagline && $site->tagline()) <span class="brand__tagline">{{ $site->tagline() }}</span> @endif
</a>
