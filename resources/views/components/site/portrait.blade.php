@props(['user', 'size' => 32])
@php $tones = ['var(--lilac)', 'var(--sun)', 'var(--mint)', 'var(--sky)', 'var(--coral)', 'var(--rose)']; @endphp
<span class="portrait" style="--size: {{ $size }}px; --tone: {{ $tones[$user->id % count($tones)] }}" aria-hidden="true">
    @if ($user->avatar)
        <img src="{{ \App\Support\Disk::of('public')->url($user->avatar) }}" alt="">
    @else
        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
    @endif
</span>
