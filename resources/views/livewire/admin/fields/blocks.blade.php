{{-- A blocks field: cut-outs tucked into the page. Expects $field (normalized by EntryEditor). --}}
@php
    $handle = $field['handle'];
    $kinds = \App\Support\Blocks::kindsFor($field);
    $every = \App\Support\Blocks::kinds();
    $blocks = array_values(array_filter((array) ($values[$handle] ?? []), fn ($b) => is_array($b) && is_string($b['type'] ?? null)));
    $tilts = ['-1.4deg', '0.9deg', '-0.6deg', '1.2deg'];
@endphp

<section class="cutouts" aria-labelledby="cutouts-{{ $handle }}"
         x-data="{ at: null, from: null, over: null,
                   drop(to) { if (this.from !== null && this.from !== to) $wire.moveBlock(@js($handle), this.from, to); this.from = this.over = null } }"
         x-on:keydown.escape="at = null">
    <div class="cutouts__head">
        <h2 id="cutouts-{{ $handle }}" class="field__label">{{ $field['display'] }}</h2>
        @if ($blocks !== [])
            <span class="field__hint">{{ count($blocks) }} {{ str('cut-out')->plural(count($blocks)) }} · drag a tab, or use ↑ ↓</span>
        @endif
    </div>
    @if ($field['instructions']) <p class="field__hint">{{ $field['instructions'] }}</p> @endif

    {{-- Choosing what to tuck in: shown at whichever gap was opened --}}
    @php
        $tray = function (int $at) use ($kinds, $handle) {
            return view('livewire.admin.fields.blocks-tray', ['kinds' => $kinds, 'handle' => $handle, 'at' => $at]);
        };
    @endphp

    <ol class="cutouts__list" role="list">
        @foreach ($blocks as $i => $block)
            @php
                $kind = $every[$block['type']] ?? null;
                $offered = isset($kinds[$block['type']]);
                $on = (bool) ($block['enabled'] ?? true);
                $id = 'cut-'.$handle.'-'.($block['id'] ?? $i);
                $name = $kind['name'] ?? \Illuminate\Support\Str::headline(\Illuminate\Support\Str::afterLast($block['type'], '.'));
            @endphp

            <li class="cutouts__gap" wire:key="gap-{{ $handle }}-{{ $block['id'] ?? $i }}">
                <button type="button" class="cutouts__add-here" x-on:click="at = at === {{ $i }} ? null : {{ $i }}" :aria-expanded="at === {{ $i }} ? 'true' : 'false'"
                        aria-label="Tuck a cut-out in here, before {{ $name }}"><span aria-hidden="true">+</span></button>
                <template x-if="at === {{ $i }}">{!! $tray($i) !!}</template>
            </li>

            <li wire:key="{{ $id }}" id="{{ $id }}"
                @class(['cutout', 'cutout--off' => ! $on, 'cutout--unknown' => ! $kind])
                style="--tone: var(--{{ $kind['tone'] ?? 'sand' }}); --tilt: {{ $tilts[$i % 4] }}"
                :class="{ 'cutout--dragging': from === {{ $i }}, 'cutout--over': over === {{ $i }} && from !== {{ $i }} }"
                x-on:dragover.prevent="over = {{ $i }}" x-on:dragleave="over === {{ $i }} && (over = null)" x-on:drop.prevent="drop({{ $i }})">
                <div class="cutout__tab" draggable="true"
                     x-on:dragstart="from = {{ $i }}; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', @js($name))"
                     x-on:dragend="from = over = null">
                    <span class="cutout__grip" aria-hidden="true" title="Drag to move">⋮⋮</span>
                    <span class="cutout__name">{{ $name }}</span>
                    @unless ($on) <span class="cutout__hidden">Hidden from readers</span> @endunless
                    <span class="cutout__tools">
                        <button type="button" class="icon-btn icon-btn--sm" wire:click="moveBlock('{{ $handle }}', {{ $i }}, {{ $i - 1 }})" @disabled($loop->first) aria-label="Move {{ $name }} up">↑</button>
                        <button type="button" class="icon-btn icon-btn--sm" wire:click="moveBlock('{{ $handle }}', {{ $i }}, {{ $i + 1 }})" @disabled($loop->last) aria-label="Move {{ $name }} down">↓</button>
                        @if ($kind)
                            <button type="button" class="icon-btn icon-btn--sm" wire:click="toggleBlock('{{ $handle }}', {{ $i }})"
                                    aria-pressed="{{ $on ? 'false' : 'true' }}" aria-label="{{ $on ? 'Hide' : 'Show' }} {{ $name }}" title="{{ $on ? 'Hide from readers' : 'Show to readers' }}">
                                @if ($on)
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                @else
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6C3.9 8.3 2 12 2 12s3.5 7 10 7c1.8 0 3.3-.5 4.6-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                                @endif
                            </button>
                            @if ($offered)
                                <button type="button" class="icon-btn icon-btn--sm" wire:click="duplicateBlock('{{ $handle }}', {{ $i }})" aria-label="Make a copy of {{ $name }}" title="Make a copy">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                                </button>
                            @endif
                        @endif
                        <button type="button" class="icon-btn icon-btn--sm icon-btn--danger" wire:click="removeBlock('{{ $handle }}', {{ $i }})" aria-label="Take out {{ $name }}" title="Take it out">×</button>
                    </span>
                </div>

                <div class="cutout__body">
                    @if (! $kind)
                        <p class="field__hint" style="margin: 0">
                            From an insert that’s switched off ({{ \Illuminate\Support\Str::before($block['type'], '.') }}). It’s kept just as it was, and shows again when the insert is back on.
                        </p>
                    @else
                        @foreach ($kind['fields'] as $name => $f)
                            @php
                                $fid = $id.'-'.$name;
                                $model = "values.{$handle}.{$i}.{$name}";
                                $value = $block[$name] ?? null;
                            @endphp
                            <div @class(['field', 'cutout__field', 'cutout__field--'.$f['type']]) wire:key="{{ $fid }}">
                                @if ($f['type'] === 'toggle')
                                    <label class="check"><input type="checkbox" wire:model="{{ $model }}"> {{ $f['display'] }}</label>
                                @elseif ($f['type'] === 'entries')
                                    <span class="field__label" id="{{ $fid }}-label">{{ $f['display'] }}</span>
                                    @include('livewire.admin.fields.entries', [
                                        'target' => "{$handle}.{$i}.{$name}", 'ids' => array_values(array_filter((array) $value, 'is_string')),
                                        'label' => 'Link a post or page', 'max' => (int) ($f['max_items'] ?? 0), 'labelId' => $fid.'-label',
                                    ])
                                @elseif (in_array($f['type'], ['picture', 'pictures'], true))
                                    <span class="field__label">{{ $f['display'] }}</span>
                                    @php $paths = array_values(array_filter((array) $value, 'is_string')); @endphp
                                    <div class="cutout__pictures">
                                        @foreach ($paths as $path)
                                            @php $pic = \App\Support\Blocks::picture($path); @endphp
                                            <span class="cutout__picture" wire:key="{{ $fid }}-{{ md5($path) }}">
                                                @if ($pic && preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', $path))
                                                    <img src="{{ $pic['url'] }}" alt="" style="object-position: {{ $pic['focus'] }}">
                                                @else
                                                    <span class="cutout__file">{{ basename($path) }}</span>
                                                @endif
                                                <button type="button" class="cutout__unpick" wire:click="removeBlockPicture('{{ $handle }}', {{ $i }}, '{{ $name }}', @js($path))" aria-label="Take out {{ basename($path) }}">×</button>
                                            </span>
                                        @endforeach
                                        @if ($f['type'] === 'pictures' || $paths === [])
                                            <button type="button" id="{{ $fid }}" class="cutout__pick" wire:click="openBlockPicker('{{ $handle }}', {{ $i }}, '{{ $name }}')">
                                                <x-admin.icon name="image" size="18" />
                                                <span>{{ $paths === [] ? 'Choose from Media' : ($f['type'] === 'pictures' ? 'Add more' : 'Change') }}</span>
                                            </button>
                                        @elseif ($f['type'] === 'picture')
                                            <button type="button" class="link-button" style="font-size: 13px" wire:click="openBlockPicker('{{ $handle }}', {{ $i }}, '{{ $name }}')">Change</button>
                                        @endif
                                    </div>
                                @else
                                    <label for="{{ $fid }}">{{ $f['display'] }}</label>
                                    @if (! empty($f['instructions'])) <p class="field__hint">{{ $f['instructions'] }}</p> @endif
                                    @switch($f['type'])
                                        @case('markdown')
                                            <textarea id="{{ $fid }}" class="cutout__words" rows="5" wire:model="{{ $model }}" placeholder="Write here. Markdown works: **bold**, _italic_, [links](https://…)."></textarea>
                                            @break
                                        @case('textarea')
                                            <textarea id="{{ $fid }}" rows="3" wire:model="{{ $model }}"></textarea>
                                            @break
                                        @case('select')
                                            <select id="{{ $fid }}" wire:model="{{ $model }}">
                                                @foreach (\App\Support\Blocks::options($f) as $optValue => $optLabel)
                                                    <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                                @endforeach
                                            </select>
                                            @break
                                        @case('link')
                                            <input id="{{ $fid }}" type="text" inputmode="url" wire:model="{{ $model }}" placeholder="https://… or /a-page-here">
                                            @break
                                        @case('number')
                                            <input id="{{ $fid }}" type="number" wire:model="{{ $model }}">
                                            @break
                                        @default
                                            <input id="{{ $fid }}" type="text" wire:model="{{ $model }}">
                                    @endswitch
                                @endif
                                @error($model) <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    @endif
                </div>
            </li>
        @endforeach

        <li class="cutouts__end" wire:key="end-{{ $handle }}">
            <button type="button" class="btn btn--dashed cutouts__add" x-on:click="at = at === {{ count($blocks) }} ? null : {{ count($blocks) }}"
                    :aria-expanded="at === {{ count($blocks) }} ? 'true' : 'false'">+ Tuck in a cut-out</button>
            <template x-if="at === {{ count($blocks) }}">{!! $tray(count($blocks)) !!}</template>
        </li>
    </ol>

    @if ($removedBlock && $removedBlock['handle'] === $handle)
        <p class="cutouts__undo" role="status">
            Took out the {{ \Illuminate\Support\Str::lower($every[$removedBlock['block']['type'] ?? '']['name'] ?? 'cut-out') }}.
            <button type="button" class="link-button" wire:click="undoRemoveBlock"><strong>Put it back</strong></button>
        </p>
    @endif

    @error('values.'.$handle) <p class="field__error">{{ $message }}</p> @enderror
</section>
