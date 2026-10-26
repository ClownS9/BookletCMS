{{-- One blueprint field. Expects $field (normalized by EntryEditor). --}}
@php
    $id = 'field-'.$field['handle'];
    $model = $field['type'] === 'terms' ? 'termInputs.'.$field['handle'] : 'values.'.$field['handle'];
    // Other fields show or hide by this one: send changes straight away.
    $live = in_array($field['handle'], $watched ?? [], true)
        ? (in_array($field['type'], ['select', 'date', 'integer', 'number'], true) ? '.live' : '.live.debounce.400ms')
        : '';
@endphp

@if ($field['type'] === 'blocks')
<div wire:key="{{ $id }}">@include('livewire.admin.fields.blocks', ['field' => $field])</div>
@elseif ($field['type'] === 'grid')
<div wire:key="{{ $id }}">@include('livewire.admin.fields.grid', ['field' => $field])</div>
@elseif ($field['type'] === 'entries')
<div class="field" wire:key="{{ $id }}">
    <span class="field__label" id="{{ $id }}-label">{{ __($field['display']) }}@if ($field['required']) <span aria-hidden="true" class="muted">*</span>@endif</span>
    @if ($field['instructions']) <p class="field__hint">{{ __($field['instructions']) }}</p> @endif
    @include('livewire.admin.fields.entries', [
        'target' => $field['handle'], 'ids' => array_values(array_filter((array) ($values[$field['handle']] ?? []), 'is_string')),
        'label' => count($field['collections']) === 1 ? __('Link a :one', ['one' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::singular((string) \App\Booklet\Facades\Booklet::collections()->find($field['collections'][0])?->title()))]) : __('Link a post or page'),
        'max' => (int) ($field['max_items'] ?? 0), 'labelId' => $id.'-label',
    ])
    @error('values.'.$field['handle']) <p class="field__error">{{ $message }}</p> @enderror
</div>
@else
<div class="field" wire:key="{{ $id }}">
    @if ($field['type'] === 'toggle')
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px">
            <span>
                <span class="field__label" id="{{ $id }}-label">{{ __($field['display']) }}</span>
                @if ($field['instructions']) <span class="field__hint" style="display: block">{{ __($field['instructions']) }}</span> @endif
            </span>
            <button type="button" class="switch" role="switch" aria-labelledby="{{ $id }}-label"
                    aria-checked="{{ ($values[$field['handle']] ?? false) ? 'true' : 'false' }}"
                    wire:click="toggle('{{ $field['handle'] }}')"></button>
        </div>
    @else
        <label for="{{ $id }}">{{ __($field['display']) }}@if ($field['required']) <span aria-hidden="true" class="muted">*</span>@endif</label>
        @if ($field['instructions']) <p class="field__hint">{{ __($field['instructions']) }}</p> @endif

        @switch($field['type'])
            @case('textarea')
            @case('markdown')
                <textarea id="{{ $id }}" rows="4" wire:model{{ $live }}="{{ $model }}"></textarea>
                @break

            @case('select')
                <select id="{{ $id }}" wire:model{{ $live }}="{{ $model }}">
                    @unless ($field['required']) <option value="">—</option> @endunless
                    @foreach ($field['options'] as $value => $label)
                        <option value="{{ is_int($value) && array_is_list($field['options']) ? $label : $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @break

            @case('terms')
                <input id="{{ $id }}" type="text" wire:model{{ $live }}="{{ $model }}" placeholder="{{ __('food, city, evening') }}">
                <p class="field__hint">{{ __('Separate with commas. New ones are added to :list.', ['list' => __(str($field['taxonomy'] ?? 'the list')->headline()->toString())]) }}</p>
                @break

            @case('assets')
                @php $path = $values[$field['handle']] ?? ''; @endphp
                <div class="picture-field">
                    <button type="button" id="{{ $id }}" class="picture-field__frame" wire:click="openPicker('{{ $field['handle'] }}')"
                            aria-label="{{ $path ? 'Change '.$field['display'] : 'Choose '.$field['display'] }}">
                        @if ($path && ($pictureUrls[$field['handle']] ?? null) && preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', $path))
                            <img src="{{ $pictureUrls[$field['handle']] }}" alt="" style="object-position: {{ \App\Support\Focus::forPath($path, $field['container'] ?? 'media') }}">
                        @elseif ($path)
                            <span class="picture-field__file">{{ basename($path) }}</span>
                        @else
                            <span class="picture-field__empty"><x-admin.icon name="image" size="22" /> {{ __('Choose from Media') }}</span>
                        @endif
                    </button>
                    @if ($path)
                        <div class="picture-field__actions">
                            <span class="picture-field__path">{{ $path }}</span>
                            <button type="button" class="link-button" style="min-height: 32px; font-size: 13px; color: var(--danger)" wire:click="clearPicture('{{ $field['handle'] }}')">{{ __('Remove') }}</button>
                        </div>
                    @endif
                </div>
                @break

            @case('date')
                <input id="{{ $id }}" type="datetime-local" wire:model{{ $live }}="{{ $model }}">
                @break

            @case('integer')
            @case('number')
                <input id="{{ $id }}" type="number" wire:model{{ $live }}="{{ $model }}">
                @break

            @case('link')
                <input id="{{ $id }}" type="url" wire:model{{ $live }}="{{ $model }}" placeholder="{{ __('https://') }}">
                @break

            @default
                <input id="{{ $id }}" type="text" wire:model{{ $live }}="{{ $model }}">
        @endswitch
    @endif

    @error($model) <p class="field__error">{{ $message }}</p> @enderror
</div>
@endif
