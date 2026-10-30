<button type="button" class="btn btn--sm dash-filter-toggle" wire:click="$toggle('filtersOpen')" aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}" aria-controls="entry-filters">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg>
    {{ __('Filters') }}@if ($filtersOn > 0) <b class="dash-filter-toggle__n">{{ $filtersOn }}</b>@endif
</button>
