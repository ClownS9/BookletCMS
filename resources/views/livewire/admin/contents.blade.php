<div style="display: contents">
    {{-- Left page: who you are, the numbers, a fresh page --}}
    <section class="page page--left">
        <div style="display: flex; align-items: center; gap: 10px">
            <x-admin.logo />
            <span class="serif" style="font-size: 22px; font-weight: 500">{{ __('booklet') }}</span>
            <button type="button" class="night-toggle" wire:click="toggleNight" aria-pressed="{{ ($user->preferences['night'] ?? false) ? 'true' : 'false' }}">
                <x-admin.icon :name="($user->preferences['night'] ?? false) ? 'sun' : 'moon'" size="15" />
                {{ ($user->preferences['night'] ?? false) ? __('Day reading') : __('Night reading') }}
            </button>
        </div>

        <a href="{{ route('admin.account') }}" class="bookplate" style="margin-top: 36px" wire:navigate>
            <x-admin.avatar :user="$user" size="56" presence />
            <span style="min-width: 0">
                <span class="eyebrow" style="display: block; font-size: 11px">{{ __('This book belongs to') }}</span>
                <span class="serif" style="display: block; margin-top: 2px; font-size: 20px">{{ $user->name }} · {{ __($user->roleName()) }}</span>
            </span>
            <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
        </a>

        <p class="eyebrow" style="margin-top: 36px">{{ now()->translatedFormat('l, j F') }}</p>
        <h1 class="display" style="margin-top: 10px">{{ __($greeting) }}, <em>{{ str($user->name)->before(' ') }}</em>.</h1>
        <p class="lede" style="margin-top: 14px">
            @if ($drafts === 0)
                {{ __('No drafts waiting. A good day to start a new page.') }}
            @else
                {{ trans_choice(':count draft is waiting for you.|:count drafts are waiting for you.', $drafts) }}
            @endif
        </p>

        @if ($update)
            <div class="update-notice" role="status">
                <span class="sticker sticker--sun" style="--tilt: -4deg">{{ __('New') }}</span>
                <span style="min-width: 0">
                    <a href="{{ route('admin.settings.updates') }}" wire:navigate class="update-notice__title">{{ __('Booklet :version is out', ['version' => $update['version']]) }}</a>
                    @if ($update['line'] !== '') <small>{{ __($update['line']) }}</small> @endif
                </span>
                <span class="update-notice__do">
                    <a href="{{ route('admin.settings.updates') }}" wire:navigate class="btn btn--sm">{{ __('See what changed') }}</a>
                    <button type="button" class="link-button" wire:click="notNowUpdate">{{ __('Not now') }}</button>
                </span>
            </div>
        @endif

        @if ($checklist !== [])
            @php
                $todo = array_values(array_filter($checklist, fn ($i) => ! $i['done']));
                $doneItems = array_values(array_filter($checklist, fn ($i) => $i['done']));
            @endphp
            <section class="first-steps" aria-labelledby="first-steps-title" x-data="{ showDone: false }">
                <div class="first-steps__head">
                    <h2 id="first-steps-title" class="eyebrow">{{ __('First steps') }}</h2>
                    <span class="first-steps__count">{{ __(':done of :all', ['done' => count($doneItems), 'all' => count($checklist)]) }}</span>
                    <button type="button" class="link-button first-steps__away" wire:click="hideChecklist">{{ __('Put away') }}</button>
                </div>
                <div class="first-steps__bar" role="progressbar" aria-label="{{ __('First steps done') }}" aria-valuemin="0" aria-valuemax="{{ count($checklist) }}" aria-valuenow="{{ count($doneItems) }}">
                    <span style="width: {{ round(count($doneItems) / max(1, count($checklist)) * 100) }}%"></span>
                </div>

                @if ($todo === [])
                    <p class="first-steps__all"><span class="sticker sticker--mint" style="--tilt: -4deg">{{ __('All set') }}</span> {{ __('Everything’s in place. The rest is writing.') }}</p>
                @else
                    <ul class="first-steps__list">
                        @foreach ($todo as $item)
                            <li wire:key="step-{{ $item['key'] }}">
                                <span class="first-steps__box" aria-hidden="true"></span>
                                <span style="min-width: 0">
                                    @if ($item['href'])
                                        <a href="{{ $item['href'] }}" @unless (str_contains($item['href'], '#')) wire:navigate @endunless>{{ __($item['label']) }}</a>
                                    @else
                                        <span>{{ __($item['label']) }}</span>
                                    @endif
                                    <small>{{ __($item['hint']) }}</small>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($doneItems !== [] && $todo !== [])
                    <button type="button" class="link-button first-steps__toggle" x-on:click="showDone = ! showDone" :aria-expanded="showDone">
                        <span x-text="showDone ? @js(__('Hide what’s done')) : @js(__('Show what’s done (:count)', ['count' => count($doneItems)]))">{{ __('Show what’s done (:count)', ['count' => count($doneItems)]) }}</span>
                    </button>
                    <ul class="first-steps__list first-steps__list--done" x-show="showDone" x-cloak>
                        @foreach ($doneItems as $item)
                            <li wire:key="done-{{ $item['key'] }}"><span class="first-steps__box first-steps__box--done" aria-hidden="true"></span><span><span class="sr-only">{{ __('Done:') }} </span>{{ __($item['label']) }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @foreach ([['Waiting for your review', $forReview, 'sky'], ['Sent back to you', $sentBack, 'rose']] as [$label, $list, $tone])
            @if ($list->isNotEmpty())
                <h2 class="eyebrow section-label section-label--gap" @if ($tone === 'sky') id="for-review" @endif>{{ __($label) }} · {{ $list->count() }}</h2>
                <ul class="review-list">
                    @foreach ($list->take(5) as $e)
                        @php $info = \App\Support\Reviews::details($e); @endphp
                        <li wire:key="rv-{{ $e->id() }}">
                            <a href="{{ route('admin.entries.edit', [$e->collectionHandle(), $e->id()]) }}" wire:navigate>
                                <span class="dot" style="--dot: var(--{{ $tone }})" aria-hidden="true"></span>
                                <span style="min-width: 0">
                                    <span class="review-list__title">{{ $e->title() ?: __('Untitled') }}</span>
                                    <span class="review-list__meta">{{ $info['by']?->name ?? __('Someone') }}{{ $info['at'] ? ' · '.$info['at']->diffForHumans() : '' }}@if ($info['note'] !== '') · “{{ \Illuminate\Support\Str::limit($info['note'], 70) }}”@endif</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($list->count() > 5)
                    <a href="{{ route('admin.entries', ['collection' => 'posts', 'status' => 'review']) }}" class="review-list__more" wire:navigate>{{ __('All :count →', ['count' => $list->count()]) }}</a>
                @endif
            @endif
        @endforeach

        {{-- What switched-on inserts have to say today --}}
        @foreach ($insertCards as $card)
            <section class="insert-card" aria-labelledby="card-{{ $card['key'] }}" wire:key="card-{{ $card['key'] }}" style="--tone: var(--{{ $card['insert']->sticker() }})">
                <h2 class="eyebrow section-label section-label--gap" id="card-{{ $card['key'] }}">
                    <span class="insert-chip insert-chip--small" aria-hidden="true"></span>
                    {{ $card['title'].($card['count'] !== null ? ' · '.number_format($card['count']) : '') }}
                </h2>
                @if ($card['text']) <p class="insert-card__text">{{ __($card['text']) }}</p> @endif
                @if ($card['items'] !== [])
                    <ul class="review-list">
                        @foreach ($card['items'] as $item)
                            <li>
                                @if ($item['href'])
                                    <a href="{{ $item['href'] }}" @if (str_starts_with($item['href'], '/') || str_starts_with($item['href'], url('/'))) wire:navigate @endif>
                                @else
                                    <span class="review-list__row">
                                @endif
                                    <span class="dot" style="--dot: var(--tone)" aria-hidden="true"></span>
                                    <span style="min-width: 0">
                                        <span class="review-list__title">{{ __($item['label']) }}</span>
                                        @if ($item['meta'] !== '') <span class="review-list__meta">{{ $item['meta'] }}</span> @endif
                                    </span>
                                @if ($item['href']) </a> @else </span> @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($card['link'])
                    <a href="{{ $card['link']['href'] }}" class="review-list__more" @if (str_starts_with($card['link']['href'], '/') || str_starts_with($card['link']['href'], url('/'))) wire:navigate @endif>{{ $card['link']['label'] }} →</a>
                @endif
            </section>
        @endforeach

        {{-- This week in the book: four figures against last week, a bar for each day --}}
        <h2 class="eyebrow section-label section-label--gap">{{ __('At a glance · this week') }}</h2>
        <div class="glance glance--week">
            @foreach ($week as $tile)
                @php $peak = max(1, max(array_column($tile['days'], 'n'))); @endphp
                <div>
                    <p class="glance__head">
                        <span class="glance__n">{{ number_format($tile['n']) }}</span>
                        <span @class(['glance__delta', 'is-up' => $tile['trend'] === 'up', 'is-down' => $tile['trend'] === 'down'])>{{ $tile['delta'] }}</span>
                    </p>
                    <p class="glance__label">
                        @if ($tile['href'])
                            <a href="{{ $tile['href'] }}" wire:navigate>{{ $tile['label'] }} →</a>
                        @else
                            {{ $tile['label'] }}
                        @endif
                    </p>
                    <div class="spark" role="img" aria-label="{{ $tile['label'] }}, {{ __('each day:') }} {{ collect($tile['days'])->map(fn ($d) => $d['date'].' '.$d['n'])->implode(', ') }}" style="--spark: var(--{{ $tile['tone'] }})">
                        @foreach ($tile['days'] as $day)
                            <span class="spark__bar" style="--h: {{ max(4, round($day['n'] / $peak * 100)) }}%" title="{{ $day['date'] }} · {{ number_format($day['n']) }}"></span>
                        @endforeach
                    </div>
                    <div class="spark__days" aria-hidden="true">
                        @foreach ($tile['days'] as $day)<span>{{ mb_substr($day['day'], 0, 2) }}</span>@endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- The month in ink --}}
        <div class="section-head">
            <h2 class="eyebrow section-label">{{ __('Visits · last 30 days') }}</h2>
            <a href="{{ route('admin.visits', ['days' => 30]) }}" class="muted" style="text-decoration: none; font-size: 14px" wire:navigate>{{ __('All the numbers →') }}</a>
        </div>
        @if ($month['total'] === 0 && $month['previous'] === 0)
            <p class="dog-ear-hint">{{ __('No visits yet. Once people read the site, the month draws itself here.') }}</p>
        @else
            <figure class="ink-chart">
                <svg viewBox="0 0 400 170" role="img" aria-label="{{ __('Visits each day for the last 30 days: :total in all.', ['total' => number_format($month['total'])]) }}@if ($month['peak']) {{ __('Busiest on :date, with :views.', ['date' => $month['peak']['date'], 'views' => number_format($month['peak']['views'])]) }}@endif">
                    @foreach ($month['grid'] as $line)
                        <line x1="30" x2="396" y1="{{ $line['y'] }}" y2="{{ $line['y'] }}" class="ink-chart__rule"/>
                        <text x="24" y="{{ $line['y'] + 3 }}" text-anchor="end" class="ink-chart__tick">{{ $line['label'] }}</text>
                    @endforeach
                    <path d="{{ $month['area'] }}" class="ink-chart__wash"/>
                    <path d="{{ $month['prev'] }}" class="ink-chart__before"/>
                    <path d="{{ $month['line'] }}" class="ink-chart__line"/>
                    @if ($month['peak'])
                        <circle cx="{{ $month['peak']['x'] }}" cy="{{ $month['peak']['y'] }}" r="4.5" class="ink-chart__peak"/>
                    @endif
                    <text x="30" y="166" class="ink-chart__tick">{{ $month['first'] }}</text>
                    <text x="213" y="166" text-anchor="middle" class="ink-chart__tick">{{ $month['middle'] }}</text>
                    <text x="396" y="166" text-anchor="end" class="ink-chart__tick">{{ __('Today') }}</text>
                </svg>
                <figcaption class="ink-chart__key">
                    <span><i class="ink-chart__swatch"></i>{{ trans_choice(':count visit|:count visits', $month['total'], ['count' => number_format($month['total'])]) }}@if ($month['change'] !== null) <b @class(['is-up' => $month['change'] > 0, 'is-down' => $month['change'] < 0])>{{ $month['change'] > 0 ? '▲' : ($month['change'] < 0 ? '▼' : '') }} {{ abs($month['change']) }}%</b>@endif</span>
                    <span><i class="ink-chart__swatch ink-chart__swatch--before"></i>{{ __('the 30 days before') }}</span>
                </figcaption>
                @if ($month['peak'])
                    <p class="margin-note">{{ __('Busiest on :date, with :views visits.', ['date' => $month['peak']['date'], 'views' => number_format($month['peak']['views'])]) }}</p>
                @endif
            </figure>

            <div class="stat-pair">
                <section aria-labelledby="from-title">
                    <h3 id="from-title" class="eyebrow section-label">{{ __('Where they came from') }}</h3>
                    @if ($sources === [])
                        <p class="dog-ear-hint">{{ __('Nothing yet.') }}</p>
                    @else
                        <div class="share-strip" role="img" aria-label="{{ collect($sources)->map(fn ($s) => $s['name'].' '.$s['share'].'%')->implode(', ') }}">
                            @foreach ($sources as $s)<span style="flex-basis: {{ $s['share'] }}%; background: var(--{{ $s['tone'] }})"></span>@endforeach
                        </div>
                        <ul class="share-key">
                            @foreach ($sources as $s)
                                <li><i style="background: var(--{{ $s['tone'] }})"></i><span>{{ $s['name'] }}</span><b>{{ $s['share'] }}%</b></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
                <section aria-labelledby="when-title">
                    <h3 id="when-title" class="eyebrow section-label">{{ __('When they read') }}</h3>
                    @php $hourPeak = max(1, max($hours['hours'])); @endphp
                    <div class="hour-bars" role="img" aria-label="{{ $hours['busiest'] !== null ? __('Visits by hour of the day; busiest around :hour.', ['hour' => \Illuminate\Support\Carbon::createFromTime($hours['busiest'])->translatedFormat('g A')]) : __('No visits yet.') }}">
                        @foreach ($hours['hours'] as $h => $n)
                            <span @class(['is-busy' => $hours['busiest'] !== null && abs($h - $hours['busiest']) <= 1]) style="--h: {{ max(3, round($n / $hourPeak * 100)) }}%"></span>
                        @endforeach
                    </div>
                    <div class="hour-bars__axis" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
                    @php $deviceTotal = array_sum($devices); @endphp
                    <p class="dog-ear-hint" style="margin-top: 8px">
                        @if ($hours['busiest'] !== null)
                            {{ __('Busiest around :hour.', ['hour' => \Illuminate\Support\Carbon::createFromTime($hours['busiest'])->translatedFormat('g A')]) }}
                        @endif
                        @if ($deviceTotal > 0)
                            {{ __(':percent% on phones.', ['percent' => (int) round($devices['phone'] / $deviceTotal * 100)]) }}
                        @endif
                    </p>
                </section>
            </div>
        @endif

        <h2 class="eyebrow section-label section-label--gap">{{ __('Dog-eared pages') }}</h2>
        @if ($dogEars->isEmpty())
            <p class="dog-ear-hint">{{ __('Fold the top corner of any page to keep your place. Folded pages wait for you here.') }}</p>
        @else
            <ul class="dog-ears">
                @foreach ($dogEars as $fold)
                    <li wire:key="fold-{{ $fold->id }}">
                        <a href="{{ $fold->url }}" wire:navigate>
                            <span class="dog-ears__corner" aria-hidden="true"></span>
                            <span class="dog-ears__title">{{ $fold->title }}</span>
                            @if ($fold->chapter) <span class="dog-ears__meta">{{ $fold->chapter }}</span> @endif
                        </a>
                        <button type="button" class="icon-btn" wire:click="unfold({{ $fold->id }})" aria-label="{{ __('Unfold :title', ['title' => $fold->title]) }}">×</button>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($openNotes > 0)
            <p class="dog-ear-hint" style="margin-top: 10px">{{ trans_choice(':count note waiting in the margins of your entries.|:count notes waiting in the margins of your entries.', $openNotes) }}</p>
        @endif

        @if ($nudges)
            <h2 class="eyebrow section-label section-label--gap">{{ __('Stacy noticed') }}</h2>
            <ul class="nudges">
                @foreach ($nudges as $nudge)
                    <li wire:key="nudge-{{ md5($nudge['key']) }}">
                        <span class="nudges__mark" aria-hidden="true"><x-admin.icon name="stacy" size="14" /></span>
                        <span class="nudges__text">{{ __($nudge['text']) }}</span>
                        <span class="nudges__do">
                            <a href="{{ $nudge['href'] }}" class="btn btn--sm" wire:navigate>{{ __($nudge['label']) }}</a>
                            <button type="button" class="link-button nudges__later" wire:click="notNow(@js($nudge['key']))">{{ __('Not now') }}</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($stickers)
            @php
                $got = $stickers->where('earned', true);
                $next = $stickers->firstWhere('earned', false);
            @endphp
            <div class="section-head">
                <h2 class="eyebrow section-label">{{ __('Stickers') }}</h2>
                <a href="{{ route('admin.account') }}#stickers" class="muted" style="text-decoration: none; font-size: 14px" wire:navigate>{{ __('Sticker book →') }}</a>
            </div>
            @if ($got->isNotEmpty())
                <ul class="sticker-strip">
                    @foreach ($got->take(7) as $s)
                        <li wire:key="sticker-{{ $s['key'] }}"><x-admin.milestone :s="$s" size="sm" /></li>
                    @endforeach
                    @if ($got->count() > 7) <li class="sticker-strip__more">+{{ $got->count() - 7 }}</li> @endif
                </ul>
            @endif
            @if ($next)
                <p class="dog-ear-hint" style="margin-top: {{ $got->isNotEmpty() ? '10px' : '0' }}">
                    {{ $got->isEmpty() ? __('Your first sticker:') : __('Next:') }} {{ rtrim($next['line'], '.') }}@if ($next['goal'] > 1 && $next['have'] > 0) <span class="mono">({{ __(':done of :all', ['done' => number_format($next['have']), 'all' => number_format($next['goal'])]) }})</span>@endif.
                </p>
            @endif
        @endif

        <form wire:submit="saveQuickDraft" style="margin-top: 32px; padding-top: 22px; border-top: 1px solid var(--rule)">
            <label for="quick-title" class="eyebrow" style="display: block; margin-bottom: 6px">{{ __('A fresh page') }}</label>
            <input id="quick-title" type="text" wire:model="quickTitle" placeholder="{{ __('Untitled') }}" class="serif"
                   style="width: 100%; padding: 10px 0; border: 0; border-bottom: 1px solid var(--rule-2); background: transparent; font-size: 24px">
            @error('quickTitle') <p class="field__error" style="margin-top: 6px">{{ $message }}</p> @enderror

            <label for="quick-body" class="sr-only">{{ __('Note') }}</label>
            <textarea id="quick-body" rows="3" wire:model="quickBody" placeholder="{{ __('Start writing…') }}" class="lined" style="margin-top: 12px"></textarea>

            <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap">
                <button type="submit" class="btn btn--ink">{{ __('Save as draft') }}</button>
                <a href="{{ route('admin.entries.create', 'posts') }}" class="btn btn--quiet" wire:navigate>{{ __('Open the full editor →') }}</a>
            </div>
        </form>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: table of contents, contributors, recent --}}
    <section class="page page--right" style="--ribbon: var(--coral)">
        <div class="ribbon" aria-hidden="true"></div>

        <p class="eyebrow">{{ __('Table of contents') }}</p>
        <h2 class="display display--page" style="margin: 8px 0 18px">{{ __('Contents') }}</h2>

        @if (array_sum(array_column($waiting, 'count')) > 0)
            {{-- Waiting for you: everything that needs a hand, counted --}}
            <h3 class="eyebrow section-label">{{ __('Waiting for you') }}</h3>
            <ul class="waiting-tiles" style="--n: {{ count($waiting) }}">
                @foreach ($waiting as $item)
                    <li>
                        <a href="{{ $item['href'] }}" wire:navigate style="--tone: var(--{{ $item['tone'] }})" @class(['is-none' => $item['count'] === 0])>
                            <span class="waiting-tiles__n">{{ number_format($item['count']) }}</span>
                            <span class="waiting-tiles__label">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @php
            $counts = collect($collections)->mapWithKeys(fn ($c) => ['list:'.$c['model']->handle() => trans_choice(':count entry|:count entries', $c['count'])])
                ->merge(['media' => trans_choice(':count file|:count files', $mediaCount), 'comments' => __(':count waiting', ['count' => $waitingComments]).(in_array($user->level(), ['admin', 'editor'], true) && ($newMessages = \App\Support\Inbox::newCount()) ? ' · '.trans_choice(':count new message|:count new messages', $newMessages) : ''), 'people' => trans_choice(':count person|:count people', $peopleCount)])
                ->all();
            $toc = collect(\App\Support\AdminNav::tableOfContents());
        @endphp
        <nav class="toc toc--book" aria-label="{{ __('Table of contents') }}">
            @foreach ($toc->where('part', 'front') as $line)
                <a href="{{ $line['href'] }}" class="toc__front" wire:navigate>
                    <span class="toc__name">{{ __($line['title']) }}</span>
                    <span class="toc__lead"></span>
                    <span class="toc__page">{{ $line['page'] }}</span>
                </a>
            @endforeach
            @foreach ($toc->where('part', 'main') as $i => $line)
                <a href="{{ $line['href'] }}" wire:navigate>
                    <span class="toc__chapter">{{ preg_replace('/\D+/', '', (string) $line['chapter']) }}</span>
                    <span class="toc__name"><span class="dot" style="--dot: var(--{{ $line['tone'] === 'ink' ? 'sand' : $line['tone'] }}); --tilt: {{ $i % 2 ? '-6deg' : '6deg' }}"></span>{{ __($line['title']) }}</span>
                    @if (isset($counts[$line['key']])) <span class="toc__count">{{ $counts[$line['key']] }}</span> @endif
                    <span class="toc__lead"></span>
                    <span class="toc__page">{{ $line['page'] }}</span>
                </a>
            @endforeach
            @foreach ($toc->where('part', 'back') as $line)
                <a href="{{ $line['href'] }}" class="toc__back" wire:navigate>
                    <span class="toc__name"><span class="toc__appendix">{{ $line['chapter'] }}</span> {{ __($line['title']) }}</span>
                    <span class="toc__lead"></span>
                    <span class="toc__page">{{ $line['page'] }}</span>
                </a>
            @endforeach
        </nav>

        @if ($user->isAdmin())
        <a href="{{ route('admin.collections') }}" class="btn btn--dashed btn--sm" style="align-self: flex-start; margin-top: 8px" wire:navigate>{{ __('+ Bind a new chapter') }}</a>
        @endif

        <div class="stat-pair">
            <section aria-labelledby="most-title">
                <h3 id="most-title" class="eyebrow section-label">{{ __('Most read · this week') }}</h3>
                @if ($mostRead === [])
                    <p class="dog-ear-hint">{{ __('Nothing read yet this week.') }}</p>
                @else
                    <ol class="most-read">
                        @foreach ($mostRead as $page)
                            <li>
                                @if ($page['href'])
                                    <a href="{{ $page['href'] }}" wire:navigate class="most-read__title"><span class="most-read__ink" style="width: {{ $page['share'] }}%" aria-hidden="true"></span><span>{{ $page['title'] }}</span></a>
                                @else
                                    <span class="most-read__title"><span class="most-read__ink" style="width: {{ $page['share'] }}%" aria-hidden="true"></span><span>{{ $page['title'] }}</span></span>
                                @endif
                                <span class="most-read__n">{{ number_format($page['views']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
            <section aria-labelledby="out-title">
                <h3 id="out-title" class="eyebrow section-label">{{ __('Published · these 4 weeks') }}</h3>
                <div class="pub-grid" role="img" aria-label="{{ trans_choice('Something went out on :count day of the last four weeks.|Something went out on :count days of the last four weeks.', $publishing['active']) }}">
                    @foreach ($publishing['days'] as $day)
                        <span @class(['pub-grid__day', 'is-future' => $day['future']]) data-level="{{ $day['level'] }}" title="{{ $day['date'] }} · {{ trans_choice(':count post|:count posts', $day['n']) }}"></span>
                    @endforeach
                </div>
                <p class="dog-ear-hint" style="margin-top: 8px">
                    {{ trans_choice(':count day with something out|:count days with something out', $publishing['active']) }}@if ($publishing['streak'] > 1) · {{ trans_choice('a :count-day streak|a :count-day streak', $publishing['streak']) }}@endif
                </p>
            </section>
        </div>

        <div class="section-head">
            <h3 class="eyebrow section-label">{{ __('Top contributors · this month') }}</h3>
            <a href="{{ route('admin.activity') }}" class="muted" style="text-decoration: none; font-size: 14px" wire:navigate>{{ __('All activity →') }}</a>
        </div>
        <ul class="contributors">
            @foreach ($contributors as $c)
                <li>
                    <x-admin.avatar :user="$c['user']" size="40" presence />
                    <span style="flex: 1 1 auto; min-width: 0">
                        <span class="contributors__name">{{ $c['user']->name }}</span>
                        <x-admin.presence :user="$c['user']" />
                    </span>
                    <span class="contributors__count"><strong>{{ $c['entries'] }}</strong> {{ trans_choice('entry|entries', $c['entries']) }}</span>
                </li>
            @endforeach
        </ul>

        <h3 class="eyebrow section-label section-label--gap">{{ __('Recently written') }}</h3>
        @if ($recent->isEmpty())
            <p class="empty-note">{{ __('Nothing written yet. Your first page starts on the left.') }}</p>
        @else
            <ul class="rows">
                @foreach ($recent as $i => $entry)
                    <li>
                        <a class="row" href="{{ route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()]) }}" wire:navigate>
                            <span class="row__title">{{ $entry->title() ?: __('Untitled') }}</span>
                            <span class="row__meta">{{ $entry->collection()?->title() }} · {{ $entry->lastModified() ? \Illuminate\Support\Carbon::parse($entry->lastModified())->diffForHumans() : '' }}</span>
                            <span class="sticker sticker--{{ $entry->status() }}" style="--tilt: {{ $i % 2 ? '-2deg' : '2deg' }}">{{ __(ucfirst($entry->status())) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.folio side="right" />
    </section>
    {{-- A new sticker: slapped onto the page once, then kept in the sticker book --}}
    @if ($celebrate)
        <div class="sticker-pop" x-data="bookletStickerPop()" x-show="open" x-cloak
             x-on:keydown.escape.window="close()" x-on:click.self="close()"
             role="dialog" aria-modal="true" aria-labelledby="sticker-pop-title">
            <div class="sticker-pop__card" x-trap.noscroll="open">
                <div class="sticker-pop__spot" aria-hidden="true">
                    <span class="sticker-pop__burst"></span>
                    <x-admin.milestone :s="$celebrate" size="lg" class="sticker-pop__sticker" />
                </div>
                <p class="eyebrow">{{ __('New sticker') }} @if ($alsoEarned) · {{ __('and :count more', ['count' => $alsoEarned]) }} @endif</p>
                <h2 id="sticker-pop-title" class="serif sticker-pop__title">{{ $celebrate['title'] }}</h2>
                <p class="sticker-pop__line">{{ __($celebrate['line']) }}</p>
                <p class="sticker-pop__when mono">{{ $celebrate['scope'] === 'book' ? __('For the whole book') : __('Yours') }} · {{ $celebrate['at'] }}</p>
                <div class="sticker-pop__do">
                    <button type="button" class="btn btn--ink" x-on:click="close()">{{ __('Stick it in the book') }}</button>
                    <a href="{{ route('admin.account') }}#stickers" class="btn btn--quiet" wire:navigate>{{ __('See them all') }}</a>
                </div>
            </div>
        </div>
    @endif
</div>
