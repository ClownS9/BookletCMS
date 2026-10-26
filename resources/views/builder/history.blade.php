{{--
    What was published, newest first: look at any of them (in a new tab),
    and put one back in the draft, to publish again.
--}}
@if ($history !== [])
    <details class="d-history" @if (request()->input('action') === 'restore') open @endif>
        <summary>Published before · {{ count($history) }}{{ count($history) >= 12 ? '+' : '' }}</summary>
        <ol class="d-history__list">
            @foreach ($history as $v)
                <li @class(['d-history__item', 'is-current' => $v['current']])>
                    <span class="d-history__when">{{ $v['when'] }}</span>
                    <span class="d-history__who">{{ $v['who'] === 'scheduled' ? 'Went live as scheduled' : 'by '.$v['who'] }}@if ($v['current']) · <b>on the site now</b>@endif</span>
                    <span class="d-history__do">
                        <a class="d-link" href="{{ $v['preview'] }}" target="_blank" rel="noopener">Look at it</a>
                        <button type="button" class="d-link" data-do="restore" data-payload="{{ $v['id'] }}"
                                data-confirm="Put the version from {{ $v['when'] }} back in the draft? Your changes since can come back with Undo.">Put this back</button>
                    </span>
                </li>
            @endforeach
        </ol>
        <p class="d-note">The {{ \App\Models\PageBuildVersion::KEEP }} most recent are kept.</p>
    </details>
@endif
