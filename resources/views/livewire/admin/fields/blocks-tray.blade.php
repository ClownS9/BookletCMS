{{-- The cut-outs on offer, to tuck in at position $at of blocks field $handle. --}}
<div class="cutouts__tray" role="group" aria-label="{{ __('Choose a cut-out') }}">
    @foreach ($kinds as $type => $kind)
        <button type="button" class="cutouts__kind" style="--tone: var(--{{ $kind['tone'] }}); --tilt: {{ ['-1.5deg', '1deg', '-0.5deg', '1.5deg'][$loop->index % 4] }}"
                wire:click="addBlock('{{ $handle }}', '{{ $type }}', {{ $at }})" x-on:click="at = null">
            <span class="cutouts__kind-name">{{ __($kind['name']) }}</span>
            <span class="cutouts__kind-about">{{ __($kind['about']) }}</span>
            @isset($kind['insert']) <span class="cutouts__kind-from">{{ __('from :insert', ['insert' => $kind['insert']->name()]) }}</span> @endisset
        </button>
    @endforeach
</div>
