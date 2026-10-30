@php $tilts = ['-4deg', '3deg', '-2deg', '4deg', '-3deg', '2deg']; @endphp

<div style="display: contents">
    {{-- Left page: filters --}}
    <section class="page page--left page--stack" style="flex-basis: 320px">
        <div>
            <a href="{{ route('admin.contents') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Contents') }}</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Activity') }}</h1>
            <x-admin.insert-links chapter="Activity" />
            <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('Every change, stamped like a library card.') }}</p>
            <div class="chapter-links">
                <a href="{{ route('admin.visits') }}" class="back" wire:navigate><x-admin.icon name="pulse" size="16" /> {{ __('Visits →') }}</a>
                <a href="{{ route('admin.bin') }}" class="back" wire:navigate><x-admin.icon name="bin" size="16" /> {{ __('The bin →') }}</a>
            </div>
        </div>

        <div>
            <h2 class="eyebrow section-label">{{ __('Show') }}</h2>
            <div class="picks">
                <button type="button" class="pick pick--row" wire:click="$set('kind', '')" aria-pressed="{{ $kind === '' ? 'true' : 'false' }}">
                    <span class="dot" style="--dot: var(--ink); --tilt: 0deg; margin: 0"></span>
                    <span style="flex: 1 1 auto">{{ __('Everything') }}</span>
                    <span class="toc__count" style="font-size: 12px">{{ $counts->sum() }}</span>
                </button>
                @foreach ($kinds as $value => [$label, $tone])
                    <button type="button" class="pick pick--row" wire:click="$set('kind', '{{ $value }}')" aria-pressed="{{ $kind === $value ? 'true' : 'false' }}">
                        <span class="dot" style="--dot: {{ $tone }}; --tilt: 0deg; margin: 0"></span>
                        <span style="flex: 1 1 auto">{{ __($label) }}</span>
                        <span class="toc__count" style="font-size: 12px">{{ $counts[$value] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="field">
            <label for="by">{{ __('Person') }}</label>
            <select id="by" wire:model.live="person">
                <option value="">{{ __('Everyone') }}</option>
                @foreach ($people as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}{{ $p->id === request()->user()?->id ? ' '.__('(you)') : '' }}</option>
                @endforeach
            </select>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the card --}}
    <section class="page page--right" style="--ribbon: var(--coral)">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="library-card">
            <div class="library-card__head">
                <h2>{{ __('Date due · Changes') }}</h2>
                <span>{{ trans_choice(':count stamp|:count stamps', $logs->total()) }}</span>
            </div>

            @if ($logs->isEmpty())
                <p class="serif muted" style="margin-top: 20px; font-style: italic; font-size: 18px">{{ __('No stamps of that kind yet.') }}</p>
            @else
                <ol class="library-card__rows">
                    @foreach ($logs as $i => $log)
                        <li wire:key="log-{{ $log->id }}">
                            <span class="library-card__stamp-col">
                                <span class="stamp" style="--tilt: {{ $tilts[$i % count($tilts)] }}">{{ strtoupper($log->created_at->format('j M')) }}</span>
                            </span>
                            <span class="dot" style="--dot: {{ $kinds[$log->kind][1] ?? 'var(--sand)' }}; --tilt: 0deg"></span>
                            <span class="library-card__text">
                                <strong>{{ $log->actor === 'stacy' ? __('Stacy') : ($log->actor === 'booklet' ? __('Booklet') : ($log->user?->name ?? __('Someone'))) }}</strong>
                                {{ __($log->action) }}
                                @if ($log->subject_label)
                                    @if ($links[$log->id] ?? null)
                                        <a href="{{ $links[$log->id] }}" class="serif" style="font-style: italic; font-size: 17px" wire:navigate>{{ $log->subject_label }}</a>
                                    @else
                                        <em class="serif" style="font-size: 17px">{{ $log->subject_label }}</em>
                                    @endif
                                @endif
                            </span>
                            <span class="library-card__time">{{ $log->created_at->format('g:i a') }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        <div style="margin-top: auto; padding-top: 24px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
            <div style="display: flex; gap: 8px">
                @if (! $logs->onFirstPage())
                    <button type="button" class="btn" wire:click="previousPage">{{ __('← Newer stamps') }}</button>
                @endif
                @if ($logs->hasMorePages())
                    <button type="button" class="btn" wire:click="nextPage">{{ __('Older stamps →') }}</button>
                @endif
            </div>
            <x-admin.folio side="right" style="margin: 0; padding: 0" />
        </div>
    </section>
</div>
