<div x-data="bookletSearch(@js(array_keys($scopes)))"
     x-on:open-search.window="show($event.detail?.q)"
     x-on:keydown.window="shortcut($event)"
     x-on:livewire:navigating.window="close(true)">
    <div class="search-modal" x-show="open" x-cloak
         role="dialog" aria-modal="true" aria-label="Search everything"
         x-on:keydown.escape.prevent.stop="close()"
         x-on:keydown.down.prevent="move(1)"
         x-on:keydown.up.prevent="move(-1)"
         x-on:keydown.tab.prevent="nextScope($event.shiftKey ? -1 : 1)"
         x-transition:enter="search-modal--in" x-transition:enter-start="search-modal--from"
         x-transition:leave="search-modal--out" x-transition:leave-end="search-modal--from">
        <div class="search-modal__overlay" x-on:click="close()"></div>

        <div class="search-modal__sheet" x-trap.noscroll="open">
            {{-- The box --}}
            <div class="search-modal__box">
                <span class="search-modal__icon" aria-hidden="true">
                    <span wire:loading.remove wire:target="q, setScope"><x-admin.icon name="search" size="20" /></span>
                    <span wire:loading wire:target="q, setScope" class="search-modal__spin"></span>
                </span>
                <label for="search-everything" class="sr-only">Search everything</label>
                <input id="search-everything" x-ref="input" type="text" wire:model.live.debounce.180ms="q"
                       placeholder="Search posts, pages, files, people… or type a command"
                       autocomplete="off" spellcheck="false"
                       x-on:keydown.enter.prevent="go($event.metaKey || $event.ctrlKey)" x-on:input="active = 0">
                <button type="button" class="search-modal__clear" x-show="$wire.q" x-cloak x-on:click="$wire.set('q', ''); $refs.input.focus()" aria-label="Clear">
                    <x-admin.icon name="close" size="14" />
                </button>
                <button type="button" class="kbd search-modal__esc" x-on:click="close()" aria-label="Close search">esc</button>
            </div>

            {{-- Filters --}}
            <div class="search-modal__filters" role="tablist" aria-label="What to search">
                @foreach ($scopes as $key => $s)
                    @php $n = $counts[$key] ?? null; @endphp
                    <button type="button" role="tab" wire:key="scope-{{ $key }}"
                            @class(['search-chip', 'is-on' => $scope === $key, 'is-empty' => $typed && $key !== 'all' && ! $n])
                            aria-selected="{{ $scope === $key ? 'true' : 'false' }}"
                            wire:click="setScope('{{ $key }}')" x-on:click="active = 0; $refs.input.focus()">
                        @if ($key !== 'all') <span class="dot" style="--dot: {{ $s['tone'] }}; --tilt: 0deg; margin: 0"></span> @endif
                        {{ $s['label'] }}
                        @if ($typed && $n !== null) <span class="search-chip__n">{{ $n }}</span> @endif
                    </button>
                @endforeach

                <span class="search-modal__status">
                    @foreach (['draft' => 'Drafts', 'scheduled' => 'Scheduled', 'published' => 'Live'] as $value => $label)
                        <button type="button" @class(['search-chip search-chip--quiet', 'is-on' => $status === $value])
                                x-on:click="toggleStatus('{{ $value }}')">{{ $label }}</button>
                    @endforeach
                </span>
            </div>

            {{-- Results --}}
            <div class="search-modal__results" x-ref="results" wire:loading.class="is-loading" wire:target="q, setScope">
                @php $i = 0; @endphp

                @if (! $typed)
                    {{-- Recently opened (remembered on this device) --}}
                    <template x-if="recent.length">
                        <section>
                            <p class="search-group">
                                Recently opened
                                <button type="button" class="link-button search-group__aside" x-on:click="forget()">Clear</button>
                            </p>
                            <template x-for="(item, n) in recent" :key="item.href">
                                <a :href="item.href" class="hit" data-hit wire:navigate x-on:click="remember(item)">
                                    <span class="hit__icon" :style="`--tone: ${item.tone}`"><x-admin.icon name="history" size="16" /></span>
                                    <span class="hit__text"><span class="hit__label serif" x-text="item.label"></span><span class="hit__sub" x-text="item.meta"></span></span>
                                    <span class="hit__go" aria-hidden="true">↵</span>
                                </a>
                            </template>
                        </section>
                    </template>

                    @if ($dogEars->isNotEmpty())
                        <p class="search-group">Dog-eared</p>
                        @foreach ($dogEars as $fold)
                            @include('livewire.admin.partials.search-hit', ['item' => ['label' => $fold->title, 'meta' => $fold->chapter ?? '', 'href' => $fold->url, 'tone' => 'var(--sun)', 'icon' => 'book'], 'needle' => ''])
                        @endforeach
                    @endif

                    @if ($recent)
                        <p class="search-group">Recently edited</p>
                        @foreach ($recent as $item)
                            @include('livewire.admin.partials.search-hit', ['item' => $item, 'needle' => ''])
                        @endforeach
                    @endif

                    <p class="search-group">Quick actions</p>
                    <div class="search-actions">
                        @foreach ($actions as $action)
                            <a href="{{ $action['href'] }}" class="search-action" data-hit
                               @if (! empty($action['external'])) target="_blank" rel="noopener" @else wire:navigate @endif
                               x-on:click="remember(@js(['label' => $action['label'], 'meta' => $action['meta'], 'href' => $action['href'], 'tone' => $action['tone']]))">
                                <span class="search-action__icon" style="--tone: {{ $action['tone'] }}"><x-admin.icon :name="$action['icon']" size="16" /></span>
                                <span><strong>{{ $action['label'] }}</strong><small>{{ $action['meta'] }}</small></span>
                            </a>
                        @endforeach
                    </div>

                    <p class="search-tips">
                        Tip: type <code>status:draft</code> to find unfinished work, or <code>in:media</code> to look only at files.
                    </p>
                @else
                    {{-- Ask Stacy --}}
                    @if ($needle !== '')
                        <a href="{{ route('admin.stacy', ['q' => $needle]) }}" class="hit hit--stacy" data-hit wire:navigate
                           x-on:click="remember({ label: @js('Ask Stacy: “'.$needle.'”'), meta: 'Stacy', href: $el.href, tone: 'var(--ink)' })">
                            <span class="hit__stacy" aria-hidden="true"><x-admin.icon name="stacy" size="16" /></span>
                            <span class="hit__text"><span class="hit__label">Ask Stacy: <em class="serif">“{{ $needle }}”</em></span><span class="hit__sub">She can find, explain or change things for you</span></span>
                            <span class="hit__go" aria-hidden="true">↵</span>
                        </a>
                    @endif

                    @foreach ($groups as $group)
                        <p class="search-group">
                            {{ $group['name'] }}
                            @if ($scope === 'all' && ($counts[$group['key']] ?? 0) > count($group['items']))
                                <button type="button" class="link-button search-group__aside" wire:click="setScope('{{ $group['key'] }}')">
                                    See all {{ $counts[$group['key']] }} →
                                </button>
                            @endif
                        </p>
                        @foreach ($group['items'] as $item)
                            @include('livewire.admin.partials.search-hit', ['item' => $item, 'needle' => $needle])
                        @endforeach
                    @endforeach

                    @if ($actions && $scope === 'all' && $needle !== '')
                        <p class="search-group">Do</p>
                        @foreach ($actions as $action)
                            @include('livewire.admin.partials.search-hit', ['item' => $action + ['meta' => $action['meta']], 'needle' => $needle])
                        @endforeach
                    @endif

                    @if ($total === 0 && ! $actions)
                        <div class="search-empty">
                            <p class="serif">Nothing called “{{ $needle ?: ($status ?? '') }}” in your book{{ $scope !== 'all' ? ' under '.$scopes[$scope]['label'] : '' }}.</p>
                            <p>
                                @if ($scope !== 'all')
                                    <button type="button" class="btn btn--sm" wire:click="setScope('all')">Search everything</button>
                                @endif
                                @if ($needle !== '')
                                    <a href="{{ route('admin.stacy', ['q' => $needle]) }}" class="btn btn--sm" wire:navigate>Ask Stacy instead</a>
                                @endif
                            </p>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Keys --}}
            <div class="search-modal__keys" aria-hidden="true">
                <span><span class="kbd">↑</span><span class="kbd">↓</span> move</span>
                <span><span class="kbd">↵</span> open</span>
                <span><span class="kbd">⌘</span><span class="kbd">↵</span> new tab</span>
                <span><span class="kbd">tab</span> next filter</span>
                <span style="margin-left: auto"><span class="kbd">/</span> or <span class="kbd">⌘</span><span class="kbd">K</span> anywhere</span>
            </div>
        </div>
    </div>
</div>
