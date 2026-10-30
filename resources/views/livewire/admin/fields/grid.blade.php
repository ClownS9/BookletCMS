{{-- Rows (Statamic's Grid): a lined index card. Expects $field (normalized by EntryEditor). --}}
@php
    $handle = $field['handle'];
    $columns = $field['columns'];
    $rows = array_values(array_filter((array) ($values[$handle] ?? []), 'is_array'));
@endphp
<section class="rows-field" aria-labelledby="rows-{{ $handle }}" x-data="{ from: null, over: null }">
    <div class="cutouts__head">
        <h2 id="rows-{{ $handle }}" class="field__label">{{ $field['display'] }}@if ($field['required']) <span aria-hidden="true" class="muted">*</span>@endif</h2>
        @if ($rows !== []) <span class="field__hint">{{ trans_choice(':count row|:count rows', count($rows)) }}</span> @endif
    </div>
    @if ($field['instructions']) <p class="field__hint">{{ $field['instructions'] }}</p> @endif

    @if ($columns === [])
        <p class="field__hint">{{ __('This field has no columns yet. Add them in Collections.') }}</p>
    @else
        <div class="rows__card" style="--cols: {{ collect($columns)->map(fn ($c) => match ($c['type']) { 'toggle' => '48px', 'picture' => '52px', 'number' => 'minmax(64px, .6fr)', 'textarea' => 'minmax(120px, 2fr)', default => 'minmax(90px, 1fr)' })->implode(' ') }}">
            <div class="rows__head" aria-hidden="true">
                <span></span>
                @foreach ($columns as $column) <span>{{ $column['display'] }}</span> @endforeach
                <span></span>
            </div>
            <ol class="rows__list" role="list">
                @foreach ($rows as $i => $row)
                    <li class="rows__row" wire:key="row-{{ $handle }}-{{ $row['id'] ?? $i }}"
                        :class="{ 'rows__row--over': over === {{ $i }} && from !== {{ $i }}, 'rows__row--dragging': from === {{ $i }} }"
                        x-on:dragover.prevent="over = {{ $i }}" x-on:drop.prevent="from !== null && from !== {{ $i }} && $wire.moveRow(@js($handle), from, {{ $i }}); from = over = null">
                        <span class="rows__grip" draggable="true" title="{{ __('Drag to move') }}" aria-hidden="true"
                              x-on:dragstart="from = {{ $i }}; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', 'row')" x-on:dragend="from = over = null">⋮⋮</span>
                        @foreach ($columns as $c => $column)
                            @php $cid = 'row-'.$handle.'-'.$i.'-'.$c; $model = "values.{$handle}.{$i}.{$c}"; @endphp
                            <span class="rows__cell rows__cell--{{ $column['type'] }}">
                                <label for="{{ $cid }}" class="rows__label">{{ $column['display'] }}</label>
                                @switch($column['type'])
                                    @case('toggle')
                                        <input id="{{ $cid }}" type="checkbox" wire:model="{{ $model }}">
                                        @break
                                    @case('picture')
                                        @php $p = $row[$c][0] ?? null; $pic = \App\Support\Blocks::picture(is_string($p) ? $p : null); @endphp
                                        <button type="button" id="{{ $cid }}" class="rows__pic" wire:click="openRowPicker(@js($handle), {{ $i }}, @js($c))" aria-label="{{ $pic ? __('Change') : __('Choose') }} {{ $column['display'] }}">
                                            @if ($pic) <img src="{{ $pic['url'] }}" alt="" style="object-position: {{ $pic['focus'] }}"> @else <x-admin.icon name="image" size="16" /> @endif
                                        </button>
                                        @break
                                    @case('textarea')
                                        <textarea id="{{ $cid }}" rows="2" wire:model="{{ $model }}"></textarea>
                                        @break
                                    @case('number')
                                        <input id="{{ $cid }}" type="number" step="any" wire:model="{{ $model }}">
                                        @break
                                    @case('link')
                                        <input id="{{ $cid }}" type="text" inputmode="url" wire:model="{{ $model }}" placeholder="{{ __('https://') }}">
                                        @break
                                    @default
                                        <input id="{{ $cid }}" type="text" wire:model="{{ $model }}">
                                @endswitch
                            </span>
                        @endforeach
                        <span class="rows__tools">
                            <button type="button" class="icon-btn icon-btn--sm" wire:click="moveRow(@js($handle), {{ $i }}, {{ $i - 1 }})" @disabled($loop->first) aria-label="Move row {{ $i + 1 }} up">↑</button>
                            <button type="button" class="icon-btn icon-btn--sm icon-btn--danger" wire:click="removeRow(@js($handle), {{ $i }})" aria-label="Take out row {{ $i + 1 }}">×</button>
                        </span>
                    </li>
                @endforeach
            </ol>
            <button type="button" class="rows__add" wire:click="addRow(@js($handle))">{{ __('+ Add a row') }}</button>
        </div>
    @endif

    @if ($removedRow && $removedRow['handle'] === $handle)
        <p class="cutouts__undo" role="status">{{ __('Took out a row.') }} <button type="button" class="link-button" wire:click="undoRemoveRow"><strong>{{ __('Put it back') }}</strong></button></p>
    @endif
    @error('values.'.$handle) <p class="field__error">{{ $message }}</p> @enderror
</section>
