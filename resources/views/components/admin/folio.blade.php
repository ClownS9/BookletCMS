{{-- The foot of a page: its number at the outer corner, and the running
     foot (the chapter on the left page, the section on the right), as in a
     printed book. --}}
@props(['side' => 'left'])
@php
    $spread = \App\Support\AdminNav::spread() ?? [];
    $number = (string) ($spread[$side === 'right' ? 'right' : 'left'] ?? '');
    $foot = $side === 'right'
        ? (string) ($spread['title'] ?? '')
        : trim((! empty($spread['chapter']) ? $spread['chapter'].' · ' : '').($spread['chapterTitle'] ?? ''));
@endphp
<p {{ $attributes->class(['page__folio', 'page__folio--'.($side === 'right' ? 'right' : 'left')]) }}>
    @if ($side === 'right')
        <span class="page__foot">{{ $foot }}</span>
        <span class="page__number">{{ $number }}</span>
    @else
        <span class="page__number">{{ $number }}</span>
        <span class="page__foot">{{ $foot }}</span>
    @endif
</p>
