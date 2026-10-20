@php
    $marks = ['ok' => ['✓', 'Fine'], 'warn' => ['!', 'Worth a look'], 'fail' => ['✕', 'Needs fixing'], 'info' => ['i', 'Good to know']];
    $fails = $checks->where('state', 'fail')->count();
    $warns = $checks->where('state', 'warn')->count();
@endphp
<div style="display: contents">
    {{-- Left page: how it's going, and what to do --}}
    <section class="page page--left page--stack" style="flex-basis: 400px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Settings</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">Check-up</h1>
            <p class="lede" style="margin-top: 12px; font-size: 15px">Whether everything the book needs from its server is there and working. Handy after moving to a new host.</p>
        </div>

        <div @class(['health-verdict', 'health-verdict--fail' => $fails, 'health-verdict--warn' => ! $fails && $warns]) role="status">
            <span class="health-verdict__mark" aria-hidden="true">{{ $fails ? '✕' : ($warns ? '!' : '✓') }}</span>
            <span>
                <strong>{{ $fails ? $fails.' '.str('thing')->plural($fails).' to fix' : ($warns ? 'Working, with '.$warns.' '.str('thing')->plural($warns).' worth a look' : 'All well') }}</strong>
                <small>{{ $fails ? 'Parts of the book won’t work properly until then.' : ($warns ? 'Nothing is broken.' : 'Everything the book needs is there and working.') }}</small>
            </span>
        </div>

        @if ($attention->isNotEmpty())
            <h2 class="eyebrow section-label" style="margin: 0">What to do</h2>
            <ol class="health-todo">
                @foreach ($attention as $c)
                    <li wire:key="todo-{{ $c['key'] }}" class="health-todo__item health-todo__item--{{ $c['state'] }}">
                        <p class="health-todo__title"><span class="health-mark health-mark--{{ $c['state'] }}" aria-hidden="true">{{ $marks[$c['state']][0] }}</span> {{ $c['label'] }}<span class="sr-only">: {{ $marks[$c['state']][1] }}</span></p>
                        <p class="health-todo__text">{{ $c['text'] }}</p>
                        @if ($c['fix']) <p class="health-todo__text">{{ $c['fix'] }}</p> @endif
                        @if ($c['command'])
                            <div class="health-command" x-data="{ copied: false }">
                                <code>{{ $c['command'] }}</code>
                                <button type="button" class="btn btn--sm" x-on:click="navigator.clipboard.writeText(@js($c['command'])); copied = true; setTimeout(() => copied = false, 1500)" aria-label="Copy the command for {{ $c['label'] }}">
                                    <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                                </button>
                            </div>
                        @endif
                        @if ($c['href']) <a href="{{ $c['href'] }}" class="health-todo__go" wire:navigate>Open {{ $c['label'] }} →</a> @endif
                    </li>
                @endforeach
            </ol>
        @endif

        <div style="margin-top: auto; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; padding-top: 12px">
            <button type="button" class="btn" wire:click="again" wire:loading.attr="disabled" wire:target="again">
                <span wire:loading.remove wire:target="again">Check again</span><span wire:loading wire:target="again">Checking…</span>
            </button>
            <p class="field__hint" style="margin: 0">On the server, <code>php artisan booklet:doctor</code> runs the same check-up.</p>
        </div>
        <x-admin.folio side="left" />
    </section>

    {{-- Right page: every check --}}
    {{-- Focusable, so the list can be scrolled from the keyboard (nothing in it is a link) --}}
    <section class="page page--right page--stack" style="--ribbon: var(--{{ $fails ? 'coral' : ($warns ? 'sun' : 'mint') }})" tabindex="0" aria-labelledby="every-check">
        <div class="ribbon" aria-hidden="true"></div>
        <div class="ribbon-clear">
            <h2 class="heading" id="every-check">Every check</h2>
            <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">{{ $checks->count() }} checks, made just now.</p>
        </div>

        @foreach ($groups as $group => $title)
            @php $mine = $checks->where('group', $group); @endphp
            @if ($mine->isNotEmpty())
                <div class="health-group">
                    <h3 class="eyebrow section-label">{{ $title }}</h3>
                    <ul class="health-list">
                        @foreach ($mine as $c)
                            <li wire:key="check-{{ $c['key'] }}">
                                <span class="health-mark health-mark--{{ $c['state'] }}" aria-hidden="true">{{ $marks[$c['state']][0] }}</span>
                                <span style="min-width: 0">
                                    <span class="health-list__label">{{ $c['label'] }}<span class="sr-only">: {{ $marks[$c['state']][1] }}</span></span>
                                    <span class="health-list__text">{{ $c['text'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
