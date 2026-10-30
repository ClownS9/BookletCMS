@props(['s', 'size' => 'md'])
@php
    $at = $s['at'] ?? null;
    $when = $at instanceof \DateTimeInterface ? $at->format('j M Y') : $at;
    $tilt = (crc32($s['key']) % 13) - 6;
    $label = $s['title'].($s['earned'] ? ($when ? ', earned '.$when : '') : ' (not yet: '.$s['line'].')');
@endphp
<span {{ $attributes->class([
        'milestone',
        'milestone--'.$size,
        'milestone--'.$s['tone'],
        'milestone--waiting' => ! $s['earned'],
    ]) }}
      style="--tilt: {{ $tilt }}deg"
      role="img" aria-label="{{ __($label) }}" title="{{ __($label) }}">
    <span class="milestone__big">{{ $s['big'] }}</span>
    @if ($size !== 'sm')
        <span class="milestone__small">{{ $s['scope'] === 'book' ? __('the book') : __('yours') }}</span>
    @endif
</span>
