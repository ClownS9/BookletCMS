@php $link = \App\Support\Blocks::safeLink($block['link']); @endphp
@if ($block['heading'] !== '' || trim($block['text']) !== '' || $link)
    <aside class="block block--button">
        @if ($block['heading'] !== '') <h2>{{ $block['heading'] }}</h2> @endif
        @if (trim($block['text']) !== '') <p>{!! nl2br(e(trim($block['text']))) !!}</p> @endif
        @if ($link)
            <a href="{{ $link }}" class="button button--ink" @if (preg_match('#^https?://#i', $link) && ! str_starts_with($link, url('/'))) rel="noopener" @endif>{{ $block['label'] ?: 'Find out more' }}</a>
        @endif
    </aside>
@endif
