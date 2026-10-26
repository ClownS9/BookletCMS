{{--
    A password box: show/hide, a Caps Lock warning, and for new passwords a
    strength meter (same reckoning as the server) or whether it matches.
    meter: show the strength meter · personal: words to keep out of it ·
    personalFrom: ids of inputs on the page whose words count too ·
    match: id of the box this one should repeat.
--}}
@props([
    'id',
    'model',
    'label',
    'autocomplete' => 'current-password',
    'meter' => false,
    'personal' => [],
    'personalFrom' => [],
    'match' => null,
    'error' => null,
    'hint' => null,
    'autofocus' => false,
])
<div {{ $attributes->class(['field', 'pw-field']) }}
     x-data="bookletPassword(@js(['meter' => (bool) $meter, 'personal' => array_values(array_filter((array) $personal)), 'personalFrom' => (array) $personalFrom, 'match' => $match]))">
    <label for="{{ $id }}">{{ $label }}</label>
    <div class="pw">
        <input id="{{ $id }}" type="password" x-ref="input" :type="show ? 'text' : 'password'"
               wire:model="{{ $model }}" autocomplete="{{ $autocomplete }}" maxlength="200"
               autocapitalize="off" autocorrect="off" spellcheck="false" @if ($autofocus) autofocus @endif
               x-on:keydown="keys($event)" x-on:keyup="keys($event)" x-on:blur="caps = false"
               @if ($meter) aria-describedby="{{ $id }}-meter" @endif>
        <button type="button" class="pw__eye" x-on:click="show = !show; $refs.input.focus()"
                :aria-pressed="show ? 'true' : 'false'" :aria-label="show ? 'Hide password' : 'Show password'" :title="show ? 'Hide password' : 'Show password'">
            <svg x-show="!show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg x-show="show" x-cloak width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
    </div>

    @if ($meter)
        <div id="{{ $id }}-meter" class="pw-meter" x-show="result" x-cloak :data-score="result ? result.score : 0" aria-live="polite">
            <span class="pw-meter__bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <span class="pw-meter__text"><strong x-text="result ? result.label : ''"></strong> <span x-text="hint"></span></span>
        </div>
    @endif
    @if ($match)
        <p class="pw-match" x-show="value" x-cloak :class="{ 'is-ok': matches }" aria-live="polite"
           x-text="matches ? @js(__('✓ Matches')) : @js(__('Doesn’t match yet'))"></p>
    @endif
    <p class="pw-caps" x-show="caps" x-cloak role="status">{{ __('Caps Lock is on.') }}</p>
    @if ($hint) <p class="field__hint">{{ $hint }}</p> @endif
    @error($error ?? $model) <p class="field__error">{{ $message }}</p> @enderror
</div>
