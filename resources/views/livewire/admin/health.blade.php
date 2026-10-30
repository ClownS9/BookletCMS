@php
    $marks = ['ok' => ['✓', 'Fine'], 'warn' => ['!', 'Worth a look'], 'fail' => ['✕', 'Needs fixing'], 'info' => ['i', 'Good to know']];
    $fails = $checks->where('state', 'fail')->count();
    $warns = $checks->where('state', 'warn')->count();
@endphp
<div style="display: contents">
    {{-- Left page: how it's going, and what to do --}}
    <section class="page page--left page--stack" style="flex-basis: 400px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Check-up') }}</h1>
            </div>
            <p class="lede" style="margin-top: 12px; font-size: 15px">{{ __('Whether everything the book needs from its server is there and working. Handy after moving to a new host.') }}</p>
        </div>

        <x-admin.card>
            <div @class(['health-verdict', 'health-verdict--fail' => $fails, 'health-verdict--warn' => ! $fails && $warns]) role="status">
                <span class="health-verdict__mark" aria-hidden="true">{{ $fails ? '✕' : ($warns ? '!' : '✓') }}</span>
                <span>
                    <strong>{{ $fails ? trans_choice(':count thing to fix|:count things to fix', $fails) : ($warns ? trans_choice('Working, with :count thing worth a look|Working, with :count things worth a look', $warns) : __('All well')) }}</strong>
                    <small>{{ $fails ? __('Parts of the book won’t work properly until then.') : ($warns ? __('Nothing is broken.') : __('Everything the book needs is there and working.')) }}</small>
                </span>
            </div>

            @if ($attention->isNotEmpty())
                <h2 class="eyebrow section-label" style="margin: 0">{{ __('What to do') }}</h2>
                <ol class="health-todo">
                    @foreach ($attention as $c)
                        <li wire:key="todo-{{ $c['key'] }}" class="health-todo__item health-todo__item--{{ $c['state'] }}">
                            <p class="health-todo__title"><span class="health-mark health-mark--{{ $c['state'] }}" aria-hidden="true">{{ $marks[$c['state']][0] }}</span> {{ __($c['label']) }}<span class="sr-only">: {{ $marks[$c['state']][1] }}</span></p>
                            <p class="health-todo__text">{{ __($c['text']) }}</p>
                            @if ($c['fix']) <p class="health-todo__text">{{ __($c['fix']) }}</p> @endif
                            @if ($c['command'])
                                <div class="health-command" x-data="{ copied: false }">
                                    <code>{{ $c['command'] }}</code>
                                    <button type="button" class="btn btn--sm" x-on:click="navigator.clipboard.writeText(@js($c['command'])); copied = true; setTimeout(() => copied = false, 1500)" aria-label="{{ __('Copy the command for :name', ['name' => __($c['label'])]) }}">
                                        <span x-show="!copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                    </button>
                                </div>
                            @endif
                            @if ($c['href']) <a href="{{ $c['href'] }}" class="health-todo__go" wire:navigate>{{ __('Open :label →', ['label' => $c['label']]) }}</a> @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            <div style="margin-top: auto; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; padding-top: 12px">
                <button type="button" class="btn" wire:click="again" wire:loading.attr="disabled" wire:target="again">
                    <span wire:loading.remove wire:target="again">{{ __('Check again') }}</span><span wire:loading wire:target="again">{{ __('Checking…') }}</span>
                </button>
                <p class="field__hint" style="margin: 0">{{ __('On the server,') }} <code>{{ __('php artisan booklet:doctor') }}</code> {{ __('runs the same check-up.') }}</p>
            </div>
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: every check --}}
    {{-- Focusable, so the list can be scrolled from the keyboard (nothing in it is a link) --}}
    <section class="page page--right page--stack" style="--ribbon: var(--{{ $fails ? 'coral' : ($warns ? 'sun' : 'mint') }})" tabindex="0" aria-labelledby="every-check">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            <div class="ribbon-clear">
                <h2 class="heading" id="every-check">{{ __('Every check') }}</h2>
                <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">{{ trans_choice(':count check, made just now.|:count checks, made just now.', $checks->count()) }}</p>
            </div>

            @foreach ($groups as $group => $title)
                @php $mine = $checks->where('group', $group); @endphp
                @if ($mine->isNotEmpty())
                    <div class="health-group">
                        <h3 class="eyebrow section-label">{{ __($title) }}</h3>
                        <ul class="health-list">
                            @foreach ($mine as $c)
                                <li wire:key="check-{{ $c['key'] }}">
                                    <span class="health-mark health-mark--{{ $c['state'] }}" aria-hidden="true">{{ $marks[$c['state']][0] }}</span>
                                    <span style="min-width: 0">
                                        <span class="health-list__label">{{ __($c['label']) }}<span class="sr-only">: {{ $marks[$c['state']][1] }}</span></span>
                                        <span class="health-list__text">{{ __($c['text']) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
