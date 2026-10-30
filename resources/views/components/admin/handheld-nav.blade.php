{{-- Phones and tablets: a top bar saying where you are, the chapter's own pages
     as tabs under it, and a dock along the bottom with Ask Stacy in the middle.
     Waiting and the Index open as sheets from the bottom. --}}
@php
    $chapters = collect(\App\Support\AdminNav::chapters());
    $current = \App\Support\AdminNav::current();
    $note = \App\Support\AdminNav::currentNote();
    $waiting = \App\Support\AdminNav::waiting();
    $waitingTotal = array_sum(array_column($waiting, 'count'));
    $contents = $chapters->firstWhere('key', 'contents');
    $second = $chapters->firstWhere('key', 'posts') ?? $chapters->firstWhere('key', 'pages') ?? $chapters->firstWhere('key', 'media');
    $onStacy = request()->routeIs('admin.stacy');
    $indexActive = ! ($contents['active'] ?? false) && ! ($second['active'] ?? false) && ! $onStacy;
    $groups = ['main' => __('Chapters'), 'tools' => __('The thumb index'), 'you' => __('The back of the book')];
    $user = request()->user();
@endphp

<div class="handheld" x-data="{ sheet: null }"
     x-on:keydown.escape.window="sheet = null"
     x-on:livewire:navigating.window="sheet = null"
     x-effect="document.documentElement.classList.toggle('drawer-open', sheet !== null)">

    <header class="topbar">
        <a href="{{ route('admin.contents') }}" class="topbar__brand" wire:navigate aria-label="{{ __('Booklet, Contents') }}">
            <x-admin.logo size="22" />
        </a>
        <span class="topbar__title">
            <span class="topbar__book">{{ rescue(fn () => \App\Booklet\Facades\Booklet::globals('site')?->get('site_name'), null, false) ?: __('booklet') }}</span>
            <span class="topbar__here">{{ __($current) }}</span>
        </span>
        <a href="#search" class="topbar__btn" x-on:click.prevent="$dispatch('open-search')" aria-label="{{ __('Search everything') }}">
            <x-admin.icon name="search" size="20" />
        </a>
        @if ($note && $note['new'] && $note['chapter'] !== 'people') {{-- People keeps its own Invite button --}}
            <a href="{{ $note['new']['href'] }}" class="topbar__new" style="--tone: var(--{{ $note['tone'] }})" wire:navigate>
                <x-admin.icon name="plus" size="16" /><span>{{ __('New') }}</span>
            </a>
        @endif
    </header>

    @if ($note)
        {{-- The chapter's own pages: what its sticky note holds on a computer --}}
        <nav class="chapter-tabs" aria-label="{{ __('Inside :chapter', ['chapter' => $note['title']]) }}" style="--tone: var(--{{ $note['tone'] }})"
             x-init="const on = $el.querySelector('[aria-current]'); if (on) $el.scrollLeft = on.offsetLeft - ($el.clientWidth - on.offsetWidth) / 2">
            @foreach (array_merge($note['items'], array_map(fn ($m) => $m + ['count' => null], $note['more'])) as $tab)
                <a href="{{ $tab['href'] }}" class="chapter-tabs__tab" wire:navigate @if ($tab['active']) aria-current="page" @endif>
                    {{ $tab['label'] }}@if ($tab['count'] !== null)<span class="chapter-tabs__n">{{ number_format($tab['count']) }}</span>@endif
                </a>
            @endforeach
        </nav>
    @endif

    {{-- The dock --}}
    <nav class="dock" aria-label="{{ __('Chapters') }}">
        @foreach ([$contents, $second] as $tab)
            @if ($tab)
                <a href="{{ $tab['href'] }}" wire:navigate class="dock__tab" style="--tone: var(--{{ $tab['tone'] }})" @if ($tab['active']) aria-current="page" @endif>
                    <span class="dock__sticker"><x-admin.icon :name="$tab['icon']" size="20" /></span>
                    <span class="dock__label">{{ $tab['label'] }}</span>
                </a>
            @endif
        @endforeach

        <a href="{{ route('admin.stacy') }}" wire:navigate class="dock__stacy" @if ($onStacy) aria-current="page" @endif>
            <span class="dock__star"><x-admin.icon name="stacy" size="24" /></span>
            <span class="dock__label">{{ __('Ask Stacy') }}</span>
        </a>

        <button type="button" class="dock__tab" style="--tone: var(--rose)" x-on:click="sheet = sheet === 'waiting' ? null : 'waiting'"
                :aria-expanded="(sheet === 'waiting').toString()" aria-expanded="false" aria-controls="sheet-waiting"
                :class="sheet === 'waiting' && 'is-open'">
            <span class="dock__sticker">
                <x-admin.icon name="comment" size="20" />
                @if ($waitingTotal > 0)
                    <span class="dock__badge">{{ $waitingTotal > 99 ? '99+' : $waitingTotal }}</span>
                @endif
            </span>
            <span class="dock__label">{{ __('Waiting') }}@if ($waitingTotal > 0)<span class="sr-only">, {{ $waitingTotal }}</span>@endif</span>
        </button>

        <button type="button" class="dock__tab" style="--tone: var(--sand)" x-on:click="sheet = sheet === 'index' ? null : 'index'"
                :aria-expanded="(sheet === 'index').toString()" aria-expanded="false" aria-controls="sheet-index"
                :class="(sheet === 'index' || ({{ $indexActive ? 'true' : 'false' }} && sheet === null)) && 'is-open'">
            <span class="dock__sticker"><x-admin.icon name="list" size="20" /></span>
            <span class="dock__label">{{ __('Index') }}</span>
        </button>
    </nav>

    {{-- Waiting: everything that needs a hand --}}
    <div class="sheet" id="sheet-waiting" x-show="sheet === 'waiting'" x-cloak role="dialog" aria-modal="true" aria-labelledby="sheet-waiting-title">
        <div class="sheet__backdrop" x-show="sheet === 'waiting'" x-transition.opacity x-on:click="sheet = null"></div>
        <div class="sheet__panel" x-show="sheet === 'waiting'" x-trap.noscroll="sheet === 'waiting'"
             x-transition:enter="sheet-in" x-transition:enter-start="sheet-in--from" x-transition:leave="sheet-out" x-transition:leave-end="sheet-in--from">
            <span class="sheet__grip" aria-hidden="true"></span>
            <div class="sheet__head">
                <h2 id="sheet-waiting-title" class="sheet__title">{{ __('Waiting for you') }}</h2>
                <button type="button" class="sheet__close" x-on:click="sheet = null"><x-admin.icon name="close" size="20" /><span class="sr-only">{{ __('Close') }}</span></button>
            </div>
            @if ($waitingTotal === 0)
                <p class="sheet__empty">{{ __('Nothing is waiting. Enjoy the quiet.') }}</p>
            @endif
            <ul class="waiting-list">
                @foreach ($waiting as $item)
                    <li>
                        <a href="{{ $item['href'] }}" wire:navigate style="--tone: var(--{{ $item['tone'] }})" @class(['is-none' => $item['count'] === 0])>
                            <span class="waiting-list__mark" aria-hidden="true"></span>
                            <span class="waiting-list__n">{{ number_format($item['count']) }}</span>
                            <span class="waiting-list__label">{{ $item['label'] }}</span>
                            <x-admin.icon name="back" size="16" class="waiting-list__go" style="transform: rotate(180deg)" />
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    {{-- The Index: everything in the book --}}
    <div class="sheet sheet--tall" id="sheet-index" x-show="sheet === 'index'" x-cloak role="dialog" aria-modal="true" aria-labelledby="sheet-index-title">
        <div class="sheet__backdrop" x-show="sheet === 'index'" x-transition.opacity x-on:click="sheet = null"></div>
        <div class="sheet__panel" x-show="sheet === 'index'" x-trap.noscroll="sheet === 'index'"
             x-transition:enter="sheet-in" x-transition:enter-start="sheet-in--from" x-transition:leave="sheet-out" x-transition:leave-end="sheet-in--from">
            <span class="sheet__grip" aria-hidden="true"></span>
            <div class="sheet__head">
                <div>
                    <p class="eyebrow" style="margin: 0; font-size: 10.5px">{{ __('Everything in the book') }}</p>
                    <h2 id="sheet-index-title" class="sheet__title">{{ __('The index') }}</h2>
                </div>
                <button type="button" class="sheet__close" x-on:click="sheet = null"><x-admin.icon name="close" size="20" /><span class="sr-only">{{ __('Close') }}</span></button>
            </div>

            <button type="button" class="sheet__search" x-on:click="sheet = null; $nextTick(() => $dispatch('open-search'))">
                <x-admin.icon name="search" size="18" /><span>{{ __('Turn to…') }}</span>
            </button>

            @foreach ($groups as $group => $heading)
                @php $list = $chapters->where('group', $group)->values(); @endphp
                @continue($list->isEmpty())
                @if ($group === 'you')
                    <details class="index-back" @if ($list->contains('active', true)) open @endif>
                        <summary><span>{{ $heading }}</span><small>{{ __('Settings & more') }}</small></summary>
                        <ul class="index-tools">
                            @foreach ($list as $chapter)
                                <li><a href="{{ $chapter['href'] }}" wire:navigate style="--tone: var(--{{ $chapter['tone'] }})" @if ($chapter['active']) aria-current="page" @endif><span class="index-tools__tab" aria-hidden="true"></span><span>{{ $chapter['label'] }}</span></a></li>
                            @endforeach
                        </ul>
                    </details>
                @else
                    <p class="sheet__group">{{ $heading }}</p>
                    <ul @class(['index-chapters' => $group === 'main', 'index-tools' => $group !== 'main'])>
                        @foreach ($list as $chapter)
                            <li>
                                <a href="{{ $chapter['href'] }}" wire:navigate style="--tone: var(--{{ $chapter['tone'] }}); --tilt: {{ $loop->odd ? '-.8deg' : '.7deg' }}" @if ($chapter['active']) aria-current="page" @endif>
                                    @if ($group === 'main')
                                        <x-admin.icon :name="$chapter['icon']" size="18" />
                                    @else
                                        <span class="index-tools__tab" aria-hidden="true"></span>
                                    @endif
                                    <span class="index-chapters__label">{{ $chapter['label'] }}</span>
                                    @if ($chapter['badge'])
                                        <span class="index-badge">{{ $chapter['badge'] }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach

            <form method="POST" action="{{ route('logout') }}" class="sheet__foot">
                @csrf
                <a href="{{ route('admin.account') }}" class="sheet__plate" wire:navigate>
                    <x-admin.avatar :user="$user" size="40" presence />
                    <span style="min-width: 0">
                        <span class="sheet__name">{{ $user->name }}</span>
                        <span class="sheet__mail">{{ $user->email }}</span>
                    </span>
                </a>
                <button type="submit" class="sheet__out"><x-admin.icon name="logout" size="16" /> {{ __('Sign out') }}</button>
            </form>
        </div>
    </div>
</div>
