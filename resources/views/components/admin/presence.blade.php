{{-- One way to show whether someone is in the book: a dot and a few words. --}}
@props(['user', 'extra' => null])
<span {{ $attributes->class(['presence', 'presence--'.$user->presence()]) }}>
    <span class="presence__dot" aria-hidden="true"></span>
    <span class="presence__text">{{ $user->presenceLabel() }}@if ($extra) <span class="presence__extra">· {{ $extra }}</span>@endif</span>
</span>
