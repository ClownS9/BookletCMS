{{-- A chapter's filters (both layouts): who wrote it, categories and tags,
     the date, the last edit, and what the search looks through. Then what's
     on, as chips, each removable. --}}
@if ($filtersOpen)
    <div class="dash-filters" id="entry-filters" role="group" aria-label="{{ __('Filters') }}" wire:key="filters">
        <label class="dash-filter">
            <span>{{ __('Written by') }}</span>
            <select class="input" wire:model.live="by">
                @foreach ($filterChoices['by'] as $value => $label)<option value="{{ $value }}" @selected((string) $value === $by)>{{ $label }}</option>@endforeach
            </select>
        </label>
        @foreach ($filterChoices['in'] as $handle => $field)
            <label class="dash-filter" wire:key="filter-in-{{ $handle }}">
                <span>{{ $field['label'] }}</span>
                <select class="input" wire:model.live="in.{{ $handle }}">
                    @foreach ($field['options'] as $value => $label)<option value="{{ $value }}" @selected((string) $value === ($in[$handle] ?? ''))>{{ $label }}</option>@endforeach
                </select>
            </label>
        @endforeach
        @if ($filterChoices['when'] !== [])
            <label class="dash-filter">
                <span>{{ __('Date') }}</span>
                <select class="input" wire:model.live="when">
                    @foreach ($filterChoices['when'] as $value => $label)<option value="{{ $value }}" @selected((string) $value === $when)>{{ $label }}</option>@endforeach
                </select>
            </label>
        @endif
        <label class="dash-filter">
            <span>{{ __('Last edited') }}</span>
            <select class="input" wire:model.live="edited">
                @foreach ($filterChoices['edited'] as $value => $label)<option value="{{ $value }}" @selected((string) $value === $edited)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <fieldset class="dash-filter dash-filter--within">
            <legend>{{ __('Search in') }}</legend>
            <div class="dash-seg">
                <label><input type="radio" value="titles" wire:model.live="within"><span>{{ __('Titles') }}</span></label>
                <label><input type="radio" value="everything" wire:model.live="within"><span>{{ __('Everything') }}</span></label>
            </div>
        </fieldset>
    </div>
@endif
@if ($activeFilters !== [] || $search !== '')
    <div class="dash-chips" role="status">
        <span class="dash-chips__count">{{ trans_choice('Showing :shown of :total|Showing :shown of :total', $total, ['shown' => number_format($shown), 'total' => number_format($total)]) }}</span>
        @if ($search !== '')
            <button type="button" class="dash-chip" wire:click="$set('search', '')" aria-label="{{ __('Stop searching for “:words”', ['words' => $search]) }}"><span>{{ __('Search') }}:</span> “{{ \Illuminate\Support\Str::limit($search, 40) }}” <i aria-hidden="true">×</i></button>
        @endif
        @foreach ($activeFilters as $f)
            <button type="button" class="dash-chip" wire:click="dropFilter('{{ $f['key'] }}')" wire:key="chip-{{ $f['key'] }}" aria-label="{{ __('Remove filter :name: :value', ['name' => $f['label'], 'value' => $f['value']]) }}"><span>{{ $f['label'] }}:</span> {{ $f['value'] }} <i aria-hidden="true">×</i></button>
        @endforeach
        <button type="button" class="link-button dash-chips__clear" wire:click="clearFilters">{{ __('Clear all') }}</button>
    </div>
@endif
