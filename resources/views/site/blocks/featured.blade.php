@php $linked = \App\Support\Links::entries($block['entries'])->reject(fn ($e) => isset($context['entry']) && $e->id() === $context['entry']->id()); @endphp
@if ($linked->isNotEmpty())
    <div class="block block--featured">
        @include('site.fields.entries', ['title' => $block['heading'] ?: __('You might also like'), 'entries' => $linked, 'site' => $site, 'handle' => 'cut-'.$block['id']])
    </div>
@endif
