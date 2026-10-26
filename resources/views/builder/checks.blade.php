{{--
    Things to look at before publishing (nothing here stops anyone): each
    one goes to its section.
--}}
@if ($checks !== [])
    <div class="d-checks">
        <p class="d-checks__head">Before you publish · {{ count($checks) }} to look at</p>
        <ul class="d-checks__list">
            @foreach ($checks as $check)
                <li>
                    @if ($check['section'])
                        <button type="button" class="d-checks__go" data-do="select" data-payload="{{ json_encode($check['section']) }}">{{ $check['words'] }}</button>
                    @else
                        <span>{{ $check['words'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@else
    <p class="d-note d-checks__ok">Nothing to look at before publishing.</p>
@endif
