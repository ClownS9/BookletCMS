{{-- The sign-up box for new posts by email (it shows when those emails are switched on). --}}
@if ($f->live)
    <div class="pb-subscribe" inert>
        @if (\App\Support\Newsletter::enabled())
            <x-site.subscribe place="page" />
        @else
            <p class="pb-nothing">{{ __('The box for signing up to new posts shows here when new posts by email are switched on.') }}</p>
        @endif
    </div>
@else
    <div class="pb-subscribe"><x-site.subscribe place="page" /></div>
@endif
