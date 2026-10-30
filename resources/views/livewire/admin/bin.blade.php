<div style="display: contents">
    {{-- Left page: what's in the bin --}}
    <section class="page page--left page--stack" style="flex-basis: 320px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.activity') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Activity') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('The bin') }}</h1>
                <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('Torn-out pages wait here for :value days, so nothing is lost by accident. Put anything back exactly as it was.', ['value' => \App\Support\Bin::DAYS]) }}</p>
            </div>
        </div>

        <x-admin.card>
            <div>
                <h2 class="eyebrow section-label">{{ __('Show') }}</h2>
                <div class="picks">
                    <button type="button" class="pick pick--row" wire:click="show('')" aria-pressed="{{ $kind === '' ? 'true' : 'false' }}">
                        <span class="dot" style="--dot: var(--ink); --tilt: 0deg; margin: 0"></span>
                        <span style="flex: 1 1 auto">{{ __('Everything') }}</span>
                        <span class="toc__count" style="font-size: 12px">{{ $counts->sum() }}</span>
                    </button>
                    @foreach ($kinds as $value => [$label, $tone])
                        <button type="button" class="pick pick--row" wire:click="show('{{ $value }}')" aria-pressed="{{ $kind === $value ? 'true' : 'false' }}">
                            <span class="dot" style="--dot: {{ $tone }}; --tilt: 0deg; margin: 0"></span>
                            <span style="flex: 1 1 auto">{{ __($label) }}</span>
                            <span class="toc__count" style="font-size: 12px">{{ $counts[$value] ?? 0 }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <p class="field__hint">
                {{ __('A page comes back to its place in the tree and in the site menus. If its address was taken in the meantime, it gets the next free one.') }}
                @unless ($canForgetAny) {{ __('You can see what you deleted, and anything you wrote.') }} @endunless
            </p>

            @if ($canEmpty && $counts->sum() > 0)
                <button type="button" class="btn btn--danger" style="align-self: flex-start" wire:click="emptyBin"
                        wire:confirm="{{ __('Empty the bin? Everything in it will be gone for good.') }}">{{ __('Empty the bin') }}</button>
            @endif
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the pile --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            <div class="ribbon-clear">
                <h2 class="heading">{{ $kind ? $kinds[$kind][0] : __('Everything in the bin') }}</h2>
                <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">{{ __('Newest first. Each one clears itself when its days run out.') }}</p>
            </div>

            @if ($items->isEmpty())
                <div class="bin-empty">
                    <p class="serif">{{ __('The bin is empty.') }}</p>
                    <p>{{ __('Deleted posts, pages, files and comments will wait here.') }}</p>
                </div>
            @else
                <ul class="bin-list">
                    @foreach ($items as $item)
                        @php $left = $item->daysLeft(); @endphp
                        <li wire:key="bin-{{ $item->id }}">
                            <span class="dot" style="--dot: {{ $kinds[$item->kind][1] ?? 'var(--sand)' }}; --tilt: 0deg; margin-top: 7px"></span>
                            <span class="bin-list__text">
                                <span class="bin-list__title serif">{{ $item->title }}</span>
                                <span class="bin-list__meta">
                                    {{ $item->meta }} · deleted {{ $item->created_at->diffForHumans() }}{{ $item->deleter ? ' by '.($item->deleter->id === request()->user()?->id ? 'you' : $item->deleter->name) : '' }}
                                </span>
                            </span>
                            <span @class(['bin-list__left', 'is-soon' => $left <= 3])>{{ trans_choice(':count day left|:count days left', $left) }}</span>
                            <span class="bin-list__do">
                                <button type="button" class="btn btn--sm btn--ink" wire:click="restore({{ $item->id }})" wire:loading.attr="disabled">{{ __('Put back') }}</button>
                                @if ($canForgetAny || (string) $item->deleted_by === (string) request()->user()?->id)
                                    <button type="button" class="link-button bin-list__forget" wire:click="forget({{ $item->id }})"
                                            wire:confirm="Delete “{{ \Illuminate\Support\Str::limit($item->title, 40) }}” for good? It can’t come back after this.">{{ __('Delete for good') }}</button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
