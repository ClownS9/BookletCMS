{{-- How many are ticked, across pages: some may be on other pages, and all
     that the filters let through can be ticked at once. --}}
@php
    $here = count(array_intersect($ticked, $pageIds));
    $more = array_diff($matchingIds ?? [], $ticked);
@endphp
<strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
@if ($here < count($ticked))
    <span class="ticked-note">{{ trans_choice('(:count on this page)|(:count on this page)', $here) }}</span>
@endif
@if ($here === count($pageIds) && $more !== [] && count($matchingIds) > count($pageIds))
    <button type="button" class="link-button ticked-all" wire:click="tickThese(@js(array_slice($matchingIds, 0, 500)))">{{ trans_choice('Choose all :count|Choose all :count', min(500, count($matchingIds)), ['count' => number_format(min(500, count($matchingIds)))]) }}</button>
@endif
