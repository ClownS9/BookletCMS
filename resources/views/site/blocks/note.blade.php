@if (trim($block['text']) !== '')
    <aside @class(['block', 'block--note', 'block--note-'.$block['kind']]) aria-label="{{ ['tip' => __('Tip'), 'info' => __('Good to know'), 'warning' => __('Careful')][$block['kind']] ?? __('Note') }}">
        <p class="block--note__label">{{ ['tip' => 'Tip', 'info' => 'Good to know', 'warning' => 'Careful'][$block['kind']] ?? 'Note' }}</p>
        <div class="prose">{{ $site->markdown($block['text']) }}</div>
    </aside>
@endif
