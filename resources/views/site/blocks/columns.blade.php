@if (trim($block['left']) !== '' || trim($block['right']) !== '')
    <div class="block block--columns">
        <div class="prose">{{ $site->markdown($block['left']) }}</div>
        <div class="prose">{{ $site->markdown($block['right']) }}</div>
    </div>
@endif
