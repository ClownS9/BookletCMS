{{--
    About a chosen picture, kept in Media (so it's right everywhere it's
    used): the spot that matters, and its description.
--}}
@php [$fx, $fy] = \App\Support\Focus::parse(str_replace(['%', ' '], ['', '-'], (string) $pic['focus'])); @endphp
<div class="d-about-picture">
    <button type="button" class="d-focus" data-focus="{{ $picPath }}" style="--fx: {{ $fx }}%; --fy: {{ $fy }}%" aria-label="{{ __('Choose the spot that matters in the picture') }}">
        <img src="{{ $pic['url'] }}" alt="" loading="lazy">
        <span class="d-focus__dot" aria-hidden="true"></span>
    </button>
    <small>{{ __('Click the spot that matters (a face, the cake): it stays in view wherever the picture is cropped.') }}</small>
    <label class="d-field">
        <span>{{ __('Description') }} <i>{{ __('(for people who can’t see it)') }}</i></span>
        <input type="text" data-alt="{{ $picPath }}" value="{{ $pic['alt'] }}" maxlength="500" placeholder="{{ __('What’s in it, in a few words') }}">
        @if (trim((string) $pic['alt']) === '') <small>{{ __('Not described yet. It’s kept in Media, for everywhere it’s used.') }}</small> @endif
    </label>
</div>
