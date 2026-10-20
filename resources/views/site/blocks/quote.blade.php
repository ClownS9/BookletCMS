@if (trim($block['quote']) !== '')
    <figure class="block block--quote">
        <blockquote>{!! nl2br(e(trim($block['quote']))) !!}</blockquote>
        @if ($block['cite'] !== '') <figcaption>{{ $block['cite'] }}</figcaption> @endif
    </figure>
@endif
