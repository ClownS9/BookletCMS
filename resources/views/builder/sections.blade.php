{{--
    Sections, stacked. Expects $sections, $types, $live, $embedded (inside a
    post, from [[builder: …]]), and maybe $h1 = false (a post: its head has
    the h1). Each section's own view is in sections/.
--}}
@php $headed = isset($h1) && $h1 === false; @endphp
@if ($embedded) <div class="pb-embed"> @endif
@foreach ($sections as $s)
    @php
        $look = $s['look'];
        $f = new \App\PageBuilder\Fields($s['fields'], $live, '', $types[$s['type']]['fields'] ?? []);
        $bg = $look['background'] === 'picture' ? \App\PageBuilder\Fields::pictureFor((string) $look['picture']) : null;
        // The first hero's (heading's, banner's) is the page's own heading (h1); everything else is h2.
        $h1 = ! $embedded && ! $headed && in_array($s['type'], ['hero', 'heading', 'banner'], true);
        $headed = $headed || $h1;
    @endphp
    <section id="{{ $s['id'] }}" @class([
            'pb', 'pb--'.$s['type'],
            'pb-bg--'.($look['background'] === 'picture' && ! $bg ? 'none' : $look['background']),
            'pb-space--'.$look['space'], 'pb-align--'.$look['align'], 'pb-width--'.$look['width'],
            'pb-show--'.$look['show'] => $look['show'] !== 'all',
            'pb-shade--'.$look['shade'] => $bg && $look['shade'] !== 'normal',
            'pb-frame--card' => $look['frame'] === 'card' && $look['background'] !== 'none' && ($look['background'] !== 'picture' || $bg),
            'pb-size--'.$look['size'] => $look['size'] !== 'normal',
            'pb-line--'.$look['line'] => $look['line'] !== 'none',
            'pb-motion--'.$look['motion'] => $look['motion'] !== 'none' && ! $live,
        ])
        @if ($bg) style="--pb-picture: url(&quot;{{ addcslashes($bg['url'], '"\\') }}&quot;); --pb-focus: {{ $bg['focus'] }}" @endif
        @if ($live) data-pb-section="{{ $s['id'] }}" data-pb-name="{{ ($types[$s['type']]['name'] ?? $s['type']).(isset($s['shared']) ? ' · shared' : '') }}" @if (! empty($types[$s['type']]['required'])) data-pb-required @elseif (! empty($types[$s['type']]['once'])) data-pb-once @endif @endif>
        @if ($look['anchor'] !== '') <span class="pb-anchor" id="{{ $look['anchor'] }}" aria-hidden="true"></span> @endif
        <div class="pb__inner">
            @include('builder.sections.'.$s['type'], ['f' => $f, 's' => $s, 'h1' => $h1, 'first' => $loop->first])
        </div>
    </section>
@endforeach
@if ($embedded) </div> @endif
