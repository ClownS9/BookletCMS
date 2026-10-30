<div style="display: contents">
    {{-- Left page: what goes in, and how it's cut --}}
    <section class="page page--left page--stack" style="flex-basis: 420px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.entries', 'posts') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Posts') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Print as a book') }}</h1>
                <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('Your writing, typeset for paper: a title page, contents, each post starting on its own page, running heads and page numbers. Print it, or save it as a PDF for a print shop.') }}</p>
            </div>
        </div>

        <x-admin.card>
            <section class="print-opts">
                <h2 class="eyebrow section-label">{{ __('What goes in') }}</h2>

                <div class="print-opts__row">
                    <div class="field">
                        <label for="pb-collection">{{ __('Chapter') }}</label>
                        <select id="pb-collection" wire:model.live="collection">
                            @foreach ($collections as $c)
                                <option value="{{ $c->handle() }}">{{ $c->title() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="pb-author">{{ __('Written by') }}</label>
                        <select id="pb-author" wire:model.live="author">
                            <option value="">{{ __('Everyone') }}</option>
                            @foreach ($people as $p)
                                <option value="{{ $p->id }}">{{ $p->byline ?: $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if ($dated)
                    <div class="print-opts__row">
                        <div class="field">
                            <label for="pb-from">{{ __('From') }}</label>
                            <input id="pb-from" type="date" wire:model.live="from">
                        </div>
                        <div class="field">
                            <label for="pb-to">{{ __('To') }}</label>
                            <input id="pb-to" type="date" wire:model.live="to">
                        </div>
                    </div>
                @endif

                <div class="print-opts__row">
                    @if ($tags->isNotEmpty())
                        <div class="field">
                            <label for="pb-tag">{{ __('Tagged') }}</label>
                            <select id="pb-tag" wire:model.live="tag">
                                <option value="">{{ __('Any tag') }}</option>
                                @foreach ($tags as $t)
                                    <option value="{{ $t->slug() }}">{{ $t->title() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @if ($editions)
                        <div class="field">
                            <label for="pb-lang">{{ __('Language') }}</label>
                            <select id="pb-lang" wire:model.live="lang">
                                <option value="">{{ __('As written') }}</option>
                                @foreach ($editions as $code => $name)
                                    <option value="{{ $code }}">{{ __(':name edition', ['name' => $name]) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="field">
                        <label for="pb-order">{{ __('Order') }}</label>
                        <select id="pb-order" wire:model.live="order">
                            <option value="oldest">{{ $dated ? __('Oldest first') : __('As on the site') }}</option>
                            <option value="newest">{{ $dated ? __('Newest first') : __('Reversed') }}</option>
                        </select>
                    </div>
                </div>
            </section>
        </x-admin.card>

        <x-admin.card>
            <section class="print-opts">
                <h2 class="eyebrow section-label">{{ __('The pages') }}</h2>
                <div class="field">
                    <label for="pb-size">{{ __('Page size') }}</label>
                    <select id="pb-size" wire:model.live="size">
                        @foreach (\App\Support\PrintBook::SIZES as $key => [$name])
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="print-opts__checks">
                    <label class="check"><input type="checkbox" wire:model.live="toc"> {{ __('A table of contents') }}</label>
                    <label class="check"><input type="checkbox" wire:model.live="covers"> {{ __('Cover pictures') }}</label>
                    <label class="check"><input type="checkbox" wire:model.live="recto"> {{ __('Start each one on a right-hand page') }}</label>
                </div>
            </section>
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: a look at what you'll get --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sun)">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            <div class="print-title ribbon-clear">
                <div class="print-proof" aria-hidden="true">
                <div @class(['print-proof__page', 'print-proof__page--'.$o['size']])>
                    <span class="print-proof__title">{{ $o['title'] }}</span>
                    @if ($o['subtitle']) <span class="print-proof__sub">{{ $o['subtitle'] }}</span> @endif
                    <span class="print-proof__rule"></span>
                    @if ($authors) <span class="print-proof__by">{{ $authors }}</span> @endif
                </div>
            </div>
                <div class="print-title__fields">
                    <h2 class="eyebrow section-label">{{ __('The title page') }}</h2>
                <div class="field">
                    <label for="pb-title">{{ __('Title') }}</label>
                    <input id="pb-title" type="text" wire:model.live.debounce.400ms="title" maxlength="120">
                </div>
                <div class="field">
                    <label for="pb-subtitle">{{ __('Subtitle') }}</label>
                    <input id="pb-subtitle" type="text" wire:model.live.debounce.400ms="subtitle" maxlength="200" placeholder="{{ __('Optional') }}">
                </div>
                <div class="field">
                    <label for="pb-byline">{{ __('By') }}</label>
                    <input id="pb-byline" type="text" wire:model.live.debounce.400ms="byline" maxlength="160" placeholder="{{ \App\Support\PrintBook::authorsOf($entries) ?: 'Your name' }}">
                    <p class="field__hint">{{ __('Leave it empty to use the names of whoever wrote these.') }}</p>
                </div>
                        </div>
            </div>

            <div>
                <p class="eyebrow">{{ __('About') }}</p>
                <p class="print-sum">
                    {!! trans_choice('<strong>:count</strong> entry|<strong>:count</strong> entries', $entries->count(), ['count' => number_format($entries->count())]) !!}
                    · <strong>{{ number_format($words) }}</strong> {{ __('words · about') }} <strong>{{ number_format($pages) }}</strong> {{ __('pages') }}
                </p>
                @if ($total > $entries->count())
                    <p class="field__hint" style="margin-top: 6px">{{ number_format($total - $entries->count()) }} more match, but one book holds {{ \App\Support\PrintBook::LIMIT }}. Narrow the dates to make a second volume.</p>
                @endif
            </div>

            @if ($entries->isEmpty())
                <p class="empty-note">{{ __('Nothing published matches these choices yet.') }}</p>
            @else
                <ol class="print-list">
                    @foreach ($entries->take(6) as $e)
                        <li wire:key="pl-{{ $e->id() }}">
                            <span class="print-list__title">{{ $e->title() ?: 'Untitled' }}</span>
                            <span class="toc__lead"></span>
                            <span class="print-list__date">{{ $e->date()?->format('j M Y') }}</span>
                        </li>
                    @endforeach
                </ol>
                @if ($entries->count() > 6)
                    <p class="field__hint">…and {{ number_format($entries->count() - 6) }} more.</p>
                @endif
            @endif

            <div class="print-go">
                <a href="{{ $href }}" target="_blank" rel="noopener" wire:click="remember"
                   @class(['btn', 'btn--ink', 'btn--lg', 'is-disabled' => $entries->isEmpty()])
                   @if ($entries->isEmpty()) aria-disabled="true" tabindex="-1" @endif>
                    <x-admin.icon name="printer" size="18" /> {{ __('Lay out the book') }}
                </a>
                <button type="button" class="link-button" wire:click="startOver">{{ __('Start over') }}</button>
            </div>
            <p class="field__hint">{{ __('It opens in a new tab and sets the type there (a few seconds for a long book). Then choose') }} <strong>{{ __('Print') }}</strong>{{ __(', or') }} <strong>{{ __('Save as PDF') }}</strong> {{ __('as the printer. Works best in Chrome, Edge or Safari.') }}</p>
        </x-admin.card>

        <x-admin.folio side="right" />
    </section>
</div>
