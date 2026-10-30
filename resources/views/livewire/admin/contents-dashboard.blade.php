<div style="display: contents">
    {{-- Contents in the Dashboard layout: the same numbers and lists as the
         book's spread, as panels and tables on one page. --}}
    <section class="page page--single page--dash" aria-labelledby="dash-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <h1 id="dash-title">{{ __('Dashboard') }}</h1>
                    <p>
                        {{ __($greeting) }}, {{ str($user->name)->before(' ') }}.
                        @if ($drafts === 0)
                            {{ __('No drafts waiting.') }}
                        @else
                            {{ trans_choice(':count draft is waiting for you.|:count drafts are waiting for you.', $drafts) }}
                        @endif
                    </p>
                </div>
                <a href="{{ route('admin.visits') }}" class="btn" wire:navigate>{{ __('Visits') }}</a>
                <a href="{{ route('admin.entries.create', 'posts') }}" class="btn btn--ink has-handheld-twin" wire:navigate><x-admin.icon name="plus" size="16" /> {{ __('New post') }}</a>
            </div>

            @include('livewire.admin.partials.contents-update')

            {{-- This week: four figures against last week, a bar for each day --}}
            <div class="dash-figures">
                @foreach ($week as $tile)
                    @php $peak = max(1, max(array_column($tile['days'], 'n'))); @endphp
                    <{{ $tile['href'] ? 'a' : 'div' }} @if ($tile['href']) href="{{ $tile['href'] }}" wire:navigate @endif class="dash-figure" style="--tone: var(--{{ $tile['tone'] }})">
                        <span class="dash-figure__label"><i aria-hidden="true"></i>{{ $tile['label'] }}</span>
                        <span class="dash-figure__n">
                            <b>{{ number_format($tile['n']) }}</b>
                            <span @class(['is-up' => $tile['trend'] === 'up', 'is-down' => $tile['trend'] === 'down'])>{{ $tile['delta'] }}</span>
                        </span>
                        <span class="dash-figure__bars" role="img" aria-label="{{ $tile['label'] }}, {{ __('each day:') }} {{ collect($tile['days'])->map(fn ($d) => $d['date'].' '.$d['n'])->implode(', ') }}">
                            @foreach ($tile['days'] as $day)<span style="--h: {{ max(8, round($day['n'] / $peak * 100)) }}%"></span>@endforeach
                        </span>
                    </{{ $tile['href'] ? 'a' : 'div' }}>
                @endforeach
            </div>

            <div class="dash-row">
                <section class="dash-card dash-card--wide" aria-labelledby="dash-visits">
                    <div class="dash-card__head">
                        <h2 id="dash-visits">{{ __('Visits, last 30 days') }}</h2>
                        <a href="{{ route('admin.visits', ['days' => 30]) }}" wire:navigate>{{ __('All the numbers') }}</a>
                    </div>
                    <div class="dash-card__body">
                        @if ($month['total'] === 0 && $month['previous'] === 0)
                            <p class="dash-empty">{{ __('No visits yet. Once people read the site, the month draws itself here.') }}</p>
                        @else
                            <figure class="ink-chart" style="margin: 0">
                                @include('livewire.admin.partials.contents-month-chart', ['chartClass' => 'dash-chart'])
                                <figcaption class="ink-chart__key">
                                    <span><i class="ink-chart__swatch"></i>{{ trans_choice(':count visit|:count visits', $month['total'], ['count' => number_format($month['total'])]) }}@if ($month['change'] !== null) <b @class(['is-up' => $month['change'] > 0, 'is-down' => $month['change'] < 0])>{{ $month['change'] > 0 ? '▲' : ($month['change'] < 0 ? '▼' : '') }} {{ abs($month['change']) }}%</b>@endif</span>
                                    <span><i class="ink-chart__swatch ink-chart__swatch--before"></i>{{ __('the 30 days before') }}</span>
                                </figcaption>
                            </figure>
                        @endif
                    </div>
                </section>

                <section class="dash-card" aria-labelledby="dash-waiting">
                    <div class="dash-card__head"><h2 id="dash-waiting">{{ __('Waiting for you') }}</h2></div>
                    @if (array_sum(array_column($waiting, 'count')) === 0)
                        <p class="dash-empty">{{ __('Nothing waiting. All caught up.') }}</p>
                    @else
                        <ul class="dash-list">
                            @foreach ($waiting as $item)
                                <li>
                                    <a href="{{ $item['href'] }}" wire:navigate style="--tone: var(--{{ $item['tone'] }})">
                                        <span @class(['dash-list__n', 'is-none' => $item['count'] === 0])>{{ number_format($item['count']) }}</span>
                                        <span class="dash-list__text">{{ $item['label'] }}</span>
                                        <span class="dash-list__go" aria-hidden="true">›</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            @if ($checklist !== [] || $forReview->isNotEmpty() || $sentBack->isNotEmpty() || $insertCards !== [] || $nudges)
                <div class="dash-row">
                    @if ($checklist !== [])
                        <section class="dash-card"><div class="dash-card__body">@include('livewire.admin.partials.contents-first-steps')</div></section>
                    @endif
                    @if ($forReview->isNotEmpty() || $sentBack->isNotEmpty())
                        <section class="dash-card"><div class="dash-card__body">@include('livewire.admin.partials.contents-reviews')</div></section>
                    @endif
                    @if ($insertCards !== [])
                        <section class="dash-card"><div class="dash-card__body">@include('livewire.admin.partials.contents-insert-cards')</div></section>
                    @endif
                    @if ($nudges)
                        <section class="dash-card"><div class="dash-card__body">@include('livewire.admin.partials.contents-nudges')</div></section>
                    @endif
                </div>
            @endif

            <div class="dash-row">
                <section class="dash-card dash-card--wide" aria-labelledby="dash-recent">
                    <div class="dash-card__head dash-card__head--plain">
                        <h2 id="dash-recent">{{ __('Recently written') }}</h2>
                        <a href="{{ route('admin.entries', 'posts') }}" wire:navigate>{{ __('All posts') }}</a>
                    </div>
                    @if ($recent->isEmpty())
                        <p class="dash-empty">{{ __('Nothing written yet.') }} <a href="{{ route('admin.entries.create', 'posts') }}" wire:navigate>{{ __('Write the first one →') }}</a></p>
                    @else
                        <div class="dash-table-wrap">
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Title') }}</th>
                                        <th scope="col" class="is-wide">{{ __('Chapter') }}</th>
                                        <th scope="col">{{ __('Edited') }}</th>
                                        <th scope="col">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recent as $entry)
                                        <tr wire:key="recent-{{ $entry->id() }}">
                                            <td><a class="dash-table__title" href="{{ route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()]) }}" wire:navigate>{{ $entry->title() ?: __('Untitled') }}</a></td>
                                            <td class="is-quiet is-wide">{{ __($entry->collection()?->title() ?? '') }}</td>
                                            <td class="is-quiet">{{ $entry->lastModified() ? \Illuminate\Support\Carbon::parse($entry->lastModified())->diffForHumans() : '—' }}</td>
                                            <td><span class="dash-status dash-status--{{ $entry->status() }}"><i aria-hidden="true"></i>{{ __(ucfirst($entry->status())) }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                @php
                    $chapterRows = collect($collections)->map(fn ($c) => ['label' => __($c['model']->title()), 'n' => $c['count'], 'href' => route('admin.entries', $c['model']->handle()), 'tone' => $c['model']->structured() ? 'mint' : 'sun', 'open' => $user->canOpen($c['model']->handle())])
                        ->filter(fn ($r) => $r['open'])
                        ->push(['label' => __('Media'), 'n' => $mediaCount, 'href' => route('admin.media'), 'tone' => 'sky', 'open' => true])
                        ->push(['label' => __('Comments'), 'n' => $waitingComments, 'href' => route('admin.comments'), 'tone' => 'rose', 'open' => true, 'waiting' => true])
                        ->values();
                @endphp
                <section class="dash-card" aria-labelledby="dash-chapters">
                    <div class="dash-card__head dash-card__head--plain">
                        <h2 id="dash-chapters">{{ __('Chapters') }}</h2>
                        @if ($user->isAdmin())
                            <a href="{{ route('admin.collections') }}" wire:navigate>{{ __('Bind a new chapter') }}</a>
                        @endif
                    </div>
                    <table class="dash-table">
                        <thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col" class="is-num">{{ __('Entries') }}</th></tr></thead>
                        <tbody>
                            @foreach ($chapterRows as $row)
                                <tr>
                                    <td><a href="{{ $row['href'] }}" class="dash-person dash-table__title" wire:navigate><span class="dot" style="--dot: var(--{{ $row['tone'] }})" aria-hidden="true"></span>{{ $row['label'] }}</a></td>
                                    <td class="is-num">{{ ($row['waiting'] ?? false) ? __(':count waiting', ['count' => number_format($row['n'])]) : number_format($row['n']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            </div>

            <div class="dash-row">
                <section class="dash-card" aria-labelledby="dash-most">
                    <div class="dash-card__head"><h2 id="dash-most">{{ __('Most read · this week') }}</h2></div>
                    @if ($mostRead === [])
                        <p class="dash-empty">{{ __('Nothing read yet this week.') }}</p>
                    @else
                        <ol class="dash-list">
                            @foreach ($mostRead as $page)
                                <li>
                                    @if ($page['href'])<a href="{{ $page['href'] }}" wire:navigate>@else<span class="dash-list__row">@endif
                                        <span class="dash-list__text">{{ $page['title'] }}</span>
                                        <span class="is-num" style="font-variant-numeric: tabular-nums; color: var(--muted)">{{ number_format($page['views']) }}</span>
                                    @if ($page['href'])</a>@else</span>@endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                <section class="dash-card" aria-labelledby="dash-people">
                    <div class="dash-card__head">
                        <h2 id="dash-people">{{ __('Top contributors · this month') }}</h2>
                        <a href="{{ route('admin.activity') }}" wire:navigate>{{ __('All activity') }}</a>
                    </div>
                    <ul class="dash-list">
                        @foreach ($contributors as $c)
                            <li>
                                <span class="dash-list__row">
                                    <x-admin.avatar :user="$c['user']" size="32" presence />
                                    <span class="dash-list__text">{{ $c['user']->name }}<small><x-admin.presence :user="$c['user']" /></small></span>
                                    <span style="color: var(--muted); font-size: 13px">{{ $c['entries'] }} {{ trans_choice('entry|entries', $c['entries']) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="dash-card" aria-labelledby="dash-quick">
                    <div class="dash-card__head"><h2 id="dash-quick">{{ __('Quick draft') }}</h2></div>
                    <form wire:submit="saveQuickDraft" class="dash-card__body" style="display: flex; flex-direction: column; gap: 12px">
                        <div class="field">
                            <label for="quick-title">{{ __('Title') }}</label>
                            <input id="quick-title" type="text" wire:model="quickTitle" placeholder="{{ __('Untitled') }}">
                            @error('quickTitle') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label for="quick-body">{{ __('Note') }}</label>
                            <textarea id="quick-body" rows="3" wire:model="quickBody" placeholder="{{ __('Start writing…') }}"></textarea>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap">
                            <button type="submit" class="btn btn--ink">{{ __('Save as draft') }}</button>
                            <a href="{{ route('admin.entries.create', 'posts') }}" class="btn btn--quiet" wire:navigate>{{ __('Open the full editor') }}</a>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </section>

    @include('livewire.admin.partials.contents-sticker-pop')
</div>
