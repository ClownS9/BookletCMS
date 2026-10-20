<div style="display: contents">
    {{-- Left page: how many came --}}
    <section class="page page--left page--stack" style="flex-basis: 420px">
        <div>
            <a href="{{ route('admin.activity') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Activity</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · Visits</p>
            <h1 class="display display--page" style="margin-top: 8px">Visits</h1>
            <p class="lede" style="margin-top: 12px; font-size: 15px">Who came to the site, what they read and where they came from. Counted without cookies or anything personal.</p>
        </div>

        <div class="filters" role="group" aria-label="Period">
            @foreach ($periods as $value => $label)
                <button type="button" class="pill" wire:click="setDays({{ $value }})" aria-pressed="{{ $days === $value ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>

        <dl class="visit-totals">
            <div>
                <dt class="eyebrow">Page views</dt>
                <dd>{{ number_format($stats['views']) }}</dd>
                @if ($change !== null)
                    <dd @class(['visit-change', 'is-up' => $change > 0, 'is-down' => $change < 0])>
                        {{ $change > 0 ? '▲' : ($change < 0 ? '▼' : '•') }} {{ abs($change) }}% on the {{ $days }} days before
                    </dd>
                @endif
            </div>
            <div>
                <dt class="eyebrow">Readers</dt>
                <dd>{{ number_format($stats['visitors']) }}</dd>
                <dd class="visit-change">about, day by day</dd>
            </div>
            <div>
                <dt class="eyebrow">Read to the end</dt>
                <dd>{{ number_format($stats['reads']) }}</dd>
                <dd class="visit-change">posts read ¾ of the way</dd>
            </div>
        </dl>

        <div class="visit-chart" role="img" aria-label="Page views per day">
            @foreach ($stats['days'] as $day)
                @php $h = $day['views'] / $peak * 100; @endphp
                <span class="visit-chart__bar" style="--h: {{ max(2, $h) }}%"
                      title="{{ \Illuminate\Support\Carbon::parse($day['day'])->format('D j M') }}: {{ number_format($day['views']) }} {{ str('view')->plural($day['views']) }}, about {{ $day['visitors'] }} {{ str('reader')->plural($day['visitors']) }}">
                    <i></i>
                </span>
            @endforeach
        </div>
        <div class="visit-chart__axis">
            <span>{{ \Illuminate\Support\Carbon::parse($stats['days'][0]['day'] ?? now())->format('j M') }}</span>
            <span>Today</span>
        </div>

        <p class="field__hint" style="margin-top: auto">
            Signed-in people, previews and bots aren’t counted. Readers whose browser asks not to be tracked are only counted in the daily total.
            Each reader is told apart for one day by a scrambled code that’s deleted after two days, so nobody is followed over time.
        </p>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: what they read, and where from --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sky)">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear">
            <h2 class="heading">Most read</h2>
        </div>
        @if ($stats['pages'] === [])
            <p class="empty-note">No visits yet in this period. Share a post and check back.</p>
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

        <h2 class="heading heading--sub" style="margin-top: 12px">Where readers come from</h2>
        @if ($stats['sources'] === [])
            <p class="empty-note">Nothing yet.</p>
        @else
            <ul class="visit-sources">
                @foreach ($stats['sources'] as $i => $src)
                    @php $share = (int) round($src['views'] / $sourceTotal * 100); @endphp
                    <li wire:key="vs-{{ md5($src['source']) }}" style="--w: {{ $share }}%; --tone: var(--{{ ['sky', 'mint', 'sun', 'lilac', 'coral', 'rose', 'sand', 'sky'][$i % 8] }})">
                        <span class="visit-sources__name">{{ $src['source'] === 'Direct' ? 'Direct (typed or bookmarked)' : $src['source'] }}</span>
                        <span class="visit-sources__n">{{ $share }}% · {{ number_format($src['views']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
