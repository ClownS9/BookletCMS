{{--
    Booklet's own layout: the masthead, the words, the colophon. Made of the same
    pieces a theme can use (x-site.page, brand, menu, tools, footer); a theme with
    templates can replace this file with its own (themes/{key}/views/components/site/layout.blade.php).
--}}
@props(['title' => null, 'description' => null, 'image' => null, 'imageAlt' => null, 'type' => 'website', 'robots' => null])
@php $menu = app(\App\Booklet\Site::class)->menu('header'); @endphp
<x-site.page :title="$title" :description="$description" :image="$image" :image-alt="$imageAlt" :type="$type" :robots="$robots">
    <header class="masthead" data-masthead>
        <div class="wrap masthead__inner">
            <x-site.brand />
            <x-site.menu :items="$menu" />
            <x-site.tools :menu-button="(bool) $menu" />
        </div>
    </header>

    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    <x-site.footer />
</x-site.page>
