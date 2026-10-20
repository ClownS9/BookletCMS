@if (trim($block['text']) !== '')
    <div class="block block--text prose">{{ $site->markdown($block['text']) }}</div>
@endif
