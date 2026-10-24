{{--
    Sections, stacked. Expects $sections, $types, $live, $embedded (inside a
    post, from [[builder: …]]). Each section's own view is in sections/.
--}}
@php $headed = false; @endphp
@if ($embedded) <div class="pb-embed"> @endif
@foreach ($sections as $s)
    @php
        $look = $s['look'];
        $f = new \BookletInserts\PageBuilder\Fields($s['fields'], $live, '', $types[$s['type']]['fields'] ?? []);
        $bg = $look['background'] === 'picture' ? \BookletInserts\PageBuilder\Fields::pictureFor((string) $look['picture']) : null;
        // The first hero's (or heading's) is the page's own heading (h1); everything else is h2.
        $h1 = ! $embedded && ! $headed && in_array($s['type'], ['hero', 'heading'], true);
        $headed = $headed || $h1;
    @endphp
    <section id="{{ $s['id'] }}" @class([
            'pb', 'pb--'.$s['type'],
            'pb-bg--'.($look['background'] === 'picture' && ! $bg ? 'none' : $look['background']),
            'pb-space--'.$look['space'], 'pb-align--'.$look['align'], 'pb-width--'.$look['width'],
        ])
        @if ($bg) style="--pb-picture: url(&quot;{{ addcslashes($bg['url'], '"\\') }}&quot;); --pb-focus: {{ $bg['focus'] }}" @endif
        @if ($live) data-pb-section="{{ $s['id'] }}" data-pb-name="{{ $types[$s['type']]['name'] ?? $s['type'] }}" @if (! empty($types[$s['type']]['required'])) data-pb-required @elseif (! empty($types[$s['type']]['once'])) data-pb-once @endif @endif>
        <div class="pb__inner">
            @include('insert-page-builder::sections.'.$s['type'], ['f' => $f, 's' => $s, 'h1' => $h1, 'first' => $loop->first])
        </div>
    </section>
@endforeach
@if ($embedded) </div> @endif
