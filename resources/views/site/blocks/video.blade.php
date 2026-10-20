@php
    $link = \App\Support\Blocks::safeLink($block['url']);
    $embed = $link ? \App\Support\Blocks::videoEmbed($link) : null;
@endphp
@if ($embed)
    <figure class="block block--video">
        <iframe src="{{ $embed }}" title="{{ $block['caption'] ?: 'Video' }}" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
        @if ($block['caption'] !== '') <figcaption>{{ $block['caption'] }}</figcaption> @endif
    </figure>
@elseif ($link)
    <p class="block block--video-link"><a href="{{ $link }}" rel="noopener">{{ $block['caption'] ?: 'Watch the video' }} →</a></p>
@endif
