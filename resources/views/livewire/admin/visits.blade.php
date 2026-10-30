<div style="display: contents">
    {{-- Left page: how many came --}}
    <section class="page page--left page--stack" style="flex-basis: 420px">
        <div>
            <a href="{{ route('admin.activity') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Activity') }}</a>
            <p class="eyebrow chapter-mark">{{ __(':value · Visits', ['value' => \App\Support\AdminNav::chapterLabel()]) }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Visits') }}</h1>
            <p class="lede" style="margin-top: 12px; font-size: 15px">{{ __('Who came to the site, what they read and where they came from. Counted without cookies or anything personal.') }}</p>
        </div>

        <div class="filters" role="group" aria-label="{{ __('Period') }}">
            @foreach ($periods as $value => $label)
                <button type="button" class="pill" wire:click="setDays({{ $value }})" aria-pressed="{{ $days === $value ? 'true' : 'false' }}">{{ __($label) }}</button>
            @endforeach
        </div>

        <dl class="visit-totals">
            <div>
                <dt class="eyebrow">{{ __('Page views') }}</dt>
                <dd>{{ number_format($stats['views']) }}</dd>
                @if ($change !== null)
                    <dd @class(['visit-change', 'is-up' => $change > 0, 'is-down' => $change < 0])>
                        {{ $change > 0 ? '▲' : ($change < 0 ? '▼' : '•') }} {{ __(':percent% on the :days days before', ['percent' => abs($change), 'days' => $days]) }}
                    </dd>
                @endif
            </div>
            <div>
                <dt class="eyebrow">{{ __('Readers') }}</dt>
                <dd>{{ number_format($stats['visitors']) }}</dd>
                <dd class="visit-change">{{ __('about, day by day') }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ __('Read to the end') }}</dt>
                <dd>{{ number_format($stats['reads']) }}</dd>
                <dd class="visit-change">{{ __('posts read ¾ of the way') }}</dd>
            </div>
        </dl>

        <div class="visit-chart" role="img" aria-label="{{ __('Page views per day') }}">
            @foreach ($stats['days'] as $day)
                @php $h = $day['views'] / $peak * 100; @endphp
                <span class="visit-chart__bar" style="--h: {{ max(2, $h) }}%"
                      title="{{ \Illuminate\Support\Carbon::parse($day['day'])->translatedFormat('D j M') }}: {{ trans_choice(':count view|:count views', $day['views'], ['count' => number_format($day['views'])]) }}, {{ trans_choice('about :count reader|about :count readers', $day['visitors']) }}">
                    <i></i>
                </span>
            @endforeach
        </div>
        <div class="visit-chart__axis">
            <span>{{ \Illuminate\Support\Carbon::parse($stats['days'][0]['day'] ?? now())->format('j M') }}</span>
            <span>{{ __('Today') }}</span>
        </div>

        <p class="field__hint" style="margin-top: auto">
            {{ __('Signed-in people, previews and bots aren’t counted. Readers whose browser asks not to be tracked are only counted in the daily total. Each reader is told apart for one day by a scrambled code that’s deleted after two days, so nobody is followed over time.') }}
        </p>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: what they read, and where from --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sky)">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear">
            <h2 class="heading">{{ __('Most read') }}</h2>
        </div>
        @if ($stats['pages'] === [])
            <p class="empty-note">{{ __('No visits yet in this period. Share a post and check back.') }}</p>
        @else
            <ol class="visit-pages">
                @foreach ($stats['pages'] as $i => $page)
                    <li wire:key="vp-{{ md5($page['path']) }}">
                        <span class="visit-pages__rank">{{ $i + 1 }}</span>
                        <span class="visit-pages__words">
                            @if ($page['edit'])
                                <a href="{{ $page['edit'] }}" wire:navigate class="visit-pages__title">{{ $page['title'] }}</a>
                            @else
                                <span class="visit-pages__title">{{ $page['title'] }}</span>
                            @endif
                            <span class="visit-pages__meta">
                                <a href="{{ url($page['path']) }}" target="_blank" rel="noopener">{{ $page['path'] }} ↗</a>
                                @if ($page['read_rate'] !== null) · {{ $page['read_rate'] }}% read to the end @endif
                            </span>
                            @if ($page['read_rate'] !== null)
                                <span class="visit-pages__depth" style="--w: {{ $page['read_rate'] }}%" aria-hidden="true"></span>
                            @endif
                        </span>
                        <span class="visit-pages__n">{{ number_format($page['views']) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif

        <h2 class="heading heading--sub" style="margin-top: 12px">{{ __('Where readers come from') }}</h2>
        @if ($stats['sources'] === [])
            <p class="empty-note">{{ __('Nothing yet.') }}</p>
        @else
            <ul class="visit-sources">
                @foreach ($stats['sources'] as $i => $src)
                    @php $share = (int) round($src['views'] / $sourceTotal * 100); @endphp
                    <li wire:key="vs-{{ md5($src['source']) }}" style="--w: {{ $share }}%; --tone: var(--{{ ['sky', 'mint', 'sun', 'lilac', 'coral', 'rose', 'sand', 'sky'][$i % 8] }})">
                        <span class="visit-sources__name">{{ $src['source'] === 'Direct' ? __('Direct (typed or bookmarked)') : $src['source'] }}</span>
                        <span class="visit-sources__n">{{ $share }}% · {{ number_format($src['views']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
