{{--
    The site's name, with its logo (or Booklet's little mark), linking home; the
    tagline too under the Banner header. <x-site.brand /> or <x-site.brand :tagline="true" />.
    Settings › Logo & icons says whether the logo, the name or both show, how
    tall the logo stands, and gives it a version for dark mode.
--}}
@props(['tagline' => null])
@php
    $site = app(\App\Booklet\Site::class);
    $tagline ??= \App\Support\Look::current()['header'] === 'banner';
    $logo = $site->logoUrl();
    $dark = $site->logoDarkUrl();
    $show = $logo ? $site->logoShow() : 'both';
@endphp
<a href="{{ url('/') }}" {{ $attributes->merge(['class' => 'brand brand--'.$show]) }} @if ($logo) style="--logo-h: {{ $site->logoHeight() }}px" @endif>
    @if ($logo && $show !== 'name')
        <img src="{{ $logo }}" alt="{{ $show === 'logo' ? $site->name() : '' }}" @class(['brand__logo', 'brand__logo--light' => $dark])>
        @if ($dark)<img src="{{ $dark }}" alt="{{ $show === 'logo' ? $site->name() : '' }}" class="brand__logo brand__logo--dark">@endif
    @elseif (! $logo)
        <span class="brand__mark" aria-hidden="true"></span>
    @endif
    @if ($show !== 'logo')<span class="brand__name">{{ $site->name() }}</span>@endif
    @if ($tagline && $site->tagline()) <span class="brand__tagline">{{ $site->tagline() }}</span> @endif
</a>
