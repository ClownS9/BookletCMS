{{--
    Things to look at before publishing (nothing here stops anyone): each
    one goes to its section.
--}}
@if ($checks !== [])
    <div class="d-checks">
        <p class="d-checks__head">{{ __('Before you publish · :count to look at', ['count' => count($checks)]) }}</p>
        <ul class="d-checks__list">
            @foreach ($checks as $check)
                <li>
                    @if ($check['section'])
                        <button type="button" class="d-checks__go" data-do="select" data-payload="{{ json_encode($check['section']) }}">{{ __($check['words']) }}</button>
                    @else
                        <span>{{ __($check['words']) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@else
    <p class="d-note d-checks__ok">{{ __('Nothing to look at before publishing.') }}</p>
@endif
