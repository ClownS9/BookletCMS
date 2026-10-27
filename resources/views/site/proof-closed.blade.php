<x-site.layout title="{{ __('This proof copy has closed') }}" robots="noindex, nofollow">
    <div class="wrap wrap--narrow section subscription">
        <span class="sticker" style="--tilt: -2deg; background: var(--mint)">{{ __('Proof copy') }}</span>
        <h1 class="post-head__title" style="margin-top: 16px">{{ __('This link has closed') }}</h1>
        <p class="lede">{{ __('Proof copies only last a few days, and can be stopped early. Ask whoever sent it for a new link. If it’s been published since, you’ll find it on the site.') }}</p>
        <p style="margin-top: 24px"><a href="{{ url('/') }}" class="button">{{ __('Go to the site') }}</a></p>
    </div>
</x-site.layout>
