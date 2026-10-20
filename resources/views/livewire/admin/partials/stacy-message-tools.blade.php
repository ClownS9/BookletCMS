{{-- Under one of Stacy's answers: copy it, or hear it --}}
@php $plain = \App\Stacy\Chat::tidy((string) $message->content); @endphp
<div class="msg-tools" x-data="{ copied: false }">
    <button type="button" class="msg-tools__btn" title="Copy"
            x-on:click="navigator.clipboard?.writeText(@js($plain)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
        <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied ✓</span>
    </button>
    <button type="button" class="msg-tools__btn" x-show="canSpeak || @js(($this->voice['engine'] ?? '') === 'natural')" title="Read it aloud"
            x-on:click="state === 'speaking' ? hush() : speak(@js($plain))">
        <span x-show="state !== 'speaking'">Read aloud</span><span x-show="state === 'speaking'" x-cloak>Stop</span>
    </button>
</div>
