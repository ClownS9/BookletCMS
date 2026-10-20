@props(['user', 'size' => 44, 'presence' => false])
@php
    $tones = ['var(--lilac)', 'var(--sun)', 'var(--mint)', 'var(--sky)', 'var(--coral)', 'var(--rose)'];
    $tone = $tones[$user->id % count($tones)];
@endphp
<span class="avatar" style="--size: {{ $size }}px; --tone: {{ $tone }}">
    @if ($user->avatar)
        <img src="{{ \App\Support\Disk::of('public')->url($user->avatar) }}" alt="">
    @else
        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
    @endif
    @if ($presence)
        <span class="avatar__presence avatar__presence--{{ $user->presence() }}" title="{{ $user->presenceLabel() }}"></span>
        <span class="sr-only">{{ $user->presenceLabel() }}</span>
    @endif
</span>
