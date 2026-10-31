@props(['set' => 'site'])
{{-- The tab and home-screen icons: the site's on the public site, Booklet's
     (on your accent colour) in the admin. See App\Support\SiteIcons. --}}
@foreach (rescue(fn () => \App\Support\SiteIcons::links($set), [], false) as $link)
    <link rel="{{ $link['rel'] }}" href="{{ $link['href'] }}" @isset($link['type']) type="{{ $link['type'] }}" @endisset @isset($link['sizes']) sizes="{{ $link['sizes'] }}" @endisset>
@endforeach
