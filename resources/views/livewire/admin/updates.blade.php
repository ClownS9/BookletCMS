<div style="display: contents">
    {{-- Left page: this version, and what's out --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Updates') }}</h1>
                <p class="lede" style="margin-top: 12px; font-size: 15px">{{ __('This is Booklet :version.', ['version' => $current]) }} {{ __('When a new version is out, it shows here with what changed. Updating makes a backup first, and you can go back.') }}</p>
            </div>
        </div>

        <x-admin.card>
            <div class="update-now">
                @if ($available)
                    <span class="sticker sticker--sun" style="--tilt: -3deg; align-self: flex-start">{{ __('New') }}</span>
                    <p class="serif" style="font-size: 24px; margin: 6px 0 0">{{ __('Booklet :version is out', ['version' => $available['version']]) }}</p>
                    @if (! empty($available['published_at']))
                        <p class="muted" style="margin: 0; font-size: 13px">{{ __('Released :when', ['when' => \Illuminate\Support\Carbon::parse($available['published_at'])->translatedFormat('j F Y')]) }}</p>
                    @endif
                @else
                    <span class="sticker sticker--mint" style="--tilt: 2deg; align-self: flex-start">{{ __('Up to date') }}</span>
                    <p class="serif" style="font-size: 22px; margin: 6px 0 0">{{ $latest && ! empty($latest['version']) ? __('This is the newest Booklet.') : __('No newer version found.') }}</p>
                @endif
                <p class="muted" style="margin: 0; font-size: 13px">
                    {{ ! empty($latest['checked_at']) ? __('Looked :when.', ['when' => \Illuminate\Support\Carbon::parse($latest['checked_at'])->diffForHumans()]) : __('Not looked yet.') }}
                    <button type="button" class="link-button" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow">
                        <span wire:loading.remove wire:target="checkNow">{{ __('Look now') }}</span><span wire:loading wire:target="checkNow">{{ __('Looking…') }}</span>
                    </button>
                </p>
            </div>

            @if ($history !== [])
                <div>
                    <p class="field__label" style="margin-bottom: 4px">{{ __('Before') }}</p>
                    <ul class="update-history">
                        @foreach ($history as $h)
                            <li>
                                <span>{{ ! empty($h['back']) ? __('Went back from :from to :to', ['from' => $h['from'], 'to' => $h['to']]) : __('Updated from :from to :to', ['from' => $h['from'], 'to' => $h['to']]) }}</span>
                                <span class="muted">{{ \Illuminate\Support\Carbon::parse($h['at'])->translatedFormat('j M Y') }}@if ($who = $people[$h['by'] ?? 0] ?? null) · {{ $who }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="field__hint" style="margin-top: auto">{{ __('New versions come from github.com/:repo, checked once a day.', ['repo' => $source]) }}</p>
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: what changed, and updating --}}
    <section class="page page--right page--aside page--stack" style="--ribbon: var(--{{ $available ? 'sun' : 'mint' }})">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            @if ($available)
                <div class="ribbon-clear">
                    <p class="eyebrow">{{ __('What changed in :version', ['version' => $available['version']]) }}</p>
                    @if ($notes !== '')
                        <div class="update-notes">{!! $notes !!}</div>
                    @else
                        <p class="muted">{{ __('The release doesn’t say.') }}</p>
                    @endif
                    @if (! empty($available['page']))
                        <p style="margin-top: 8px"><a href="{{ $available['page'] }}" target="_blank" rel="noopener noreferrer" class="back">{{ __('Its page on GitHub ↗') }}</a></p>
                    @endif
                </div>

                <div class="update-go">
                    @php
                        $steps = ['backup' => __('A backup of the book'), 'downloaded' => __('Download it'), 'checked' => __('Check it’s whole and genuine'), 'applied' => __('Swap Booklet’s files (the old ones kept)'), 'done' => __('Bring the database up to date')];
                        $order = array_keys($steps);
                        $reached = $ready ? 'checked' : $stage;
                        $at = $reached === null ? -1 : array_search($reached, $order, true);
                    @endphp
                    <ol class="update-steps" aria-label="{{ __('How updating goes') }}">
                        @foreach ($steps as $key => $label)
                            @php $i = $loop->index; @endphp
                            <li @class(['is-done' => $i <= $at, 'is-current' => ($preparing || $ready) && $i === $at + 1])>
                                <span class="setup__n" aria-hidden="true">{{ $i <= $at ? '✓' : $i + 1 }}</span>
                                <span>{{ __($label) }}</span>
                            </li>
                        @endforeach
                    </ol>

                    @if ($problem)
                        <p class="hook-result" role="alert"><strong>{{ __('It didn’t update.') }}</strong> <span>{{ $problem }}</span></p>
                    @endif

                    @if ($blocked)
                        <p class="hook-result" role="status"><strong>{{ __('Updating from here won’t work on this server.') }}</strong> <span>{{ $blocked }}</span></p>
                    @elseif ($ready)
                        {{-- Ready: on to swapping the files (a plain form: this page's own files are about to change). --}}
                        <form method="POST" action="{{ route('admin.updates.apply') }}" x-data x-init="$el.submit()">
                            @csrf
                            <p class="hook-result is-ok" role="status"><strong>{{ __('Downloaded and checked. Putting it in place…') }}</strong> <span>{{ __('Readers see “back in a moment” for a few seconds.') }}</span></p>
                            <noscript><button type="submit" class="btn btn--ink">{{ __('Put it in place') }}</button></noscript>
                        </form>
                    @elseif ($preparing)
                        <div x-data x-init="(async () => { while (await $wire.step()) {} })()" role="status" class="update-working">
                            <span class="search-modal__spin" aria-hidden="true"></span> {{ __('Working on it. Keep this page open.') }}
                        </div>
                    @else
                        <div class="update-actions">
                            <button type="button" class="btn btn--ink" wire:click="start">{{ __('Update to :version', ['version' => $available['version']]) }}</button>
                            @if ($stage) <button type="button" class="btn btn--quiet" wire:click="cancel">{{ __('Start over') }}</button> @endif
                        </div>
                        <p class="field__hint" style="margin: 0">{{ __('Takes a minute or two. Your posts, pictures, settings and themes aren’t touched; only Booklet’s own files change.') }}</p>
                    @endif
                </div>
            @else
                <div class="ribbon-clear">
                    <p class="eyebrow">{{ __('Nothing to do') }}</p>
                    <p class="lede">{{ __('When a new version of Booklet is out, it shows here (and on the contents page) with what changed in it.') }}</p>
                    @if ($blocked)
                        <p class="hook-result" role="status" style="margin-top: 16px"><strong>{{ __('Updating from here won’t work on this server.') }}</strong> <span>{{ $blocked }}</span></p>
                    @endif
                </div>
            @endif

            @if ($undoable)
                <div class="update-back">
                    <p class="field__label" style="margin: 0">{{ __('Go back to :version', ['version' => $undoable['from']]) }}</p>
                    <p class="field__hint" style="margin: 0">{{ __('Updated to :to :when. Going back puts Booklet :from’s files back and undoes its database changes; what you’ve written since stays. Anything made with features new in :to may be lost.', ['to' => $undoable['to'], 'from' => $undoable['from'], 'when' => \Illuminate\Support\Carbon::parse($undoable['at'])->diffForHumans()]) }}</p>
                    @if (! empty($undoable['backup']))
                        <p class="field__hint" style="margin: 0">{{ __('The backup made before updating is in') }} <a href="{{ route('admin.backups') }}" wire:navigate>{{ __('Backups') }}</a>.</p>
                    @endif
                    <form method="POST" action="{{ route('admin.updates.undo') }}" onsubmit="return confirm(@js(__('Go back to Booklet :version?', ['version' => $undoable['from']])))">
                        @csrf
                        <button type="submit" class="btn btn--sm">{{ __('Go back to :version', ['version' => $undoable['from']]) }}</button>
                    </form>
                </div>
            @endif
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
