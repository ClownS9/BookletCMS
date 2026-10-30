@php
    $entry = $this->entry;
    $chosen = $this->chosen;
    $tagTones = ['started a draft' => 'sun', 'published' => 'mint', 'scheduled' => 'sky', 'restored' => 'lilac', 'edited' => 'sand'];
@endphp

<div style="display: contents">
    {{-- Left page: the timeline --}}
    <section class="page page--left" style="flex-basis: 360px">
        <div class="page-head">
            <x-admin.crumbs>
                <a href="{{ route('admin.entries.edit', [$collection, $entryId]) }}" class="back" wire:navigate>
                <x-admin.icon name="back" size="16" /> {{ __('Back to the editor') }}
                </a>
            </x-admin.crumbs>
            <p class="eyebrow" style="margin-top: 18px">{{ $entry->title() ?: 'Untitled' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Editions') }}</h1>
        </div>
        <p class="lede" style="margin: 12px 0 22px; font-size: 15px">{{ __('Every save is kept. Pick one to see what changed, compare it with any other, and bring back all of it or just a part.') }}</p>

        @if ($count === 0)
            <p class="empty-note">{{ __('No editions yet. They start with the next save.') }}</p>
        @else
            <ol class="picks">
                @foreach ($this->editions as $i => $edition)
                    @php
                        $number = $count - $i;
                        $who = $users[$edition->user()] ?? null;
                    @endphp
                    <li wire:key="edition-{{ $edition->id() }}">
                        <button type="button" class="pick" wire:click="pick('{{ $edition->id() }}')" aria-pressed="{{ $chosen?->id() === $edition->id() ? 'true' : 'false' }}">
                            <span @class(['timeline-dot', 'timeline-dot--on' => $chosen?->id() === $edition->id()])></span>
                            <span style="flex: 1 1 auto; min-width: 0">
                                <span class="pick__title" style="font-size: 18px">{{ $i === 0 ? __('Latest') : 'Edition '.$number }}</span>
                                <span class="pick__meta">{{ $who?->name ?? __('Someone') }} · {{ $edition->date()?->diffForHumans() }}</span>
                            </span>
                            <span class="sticker sticker--sm sticker--{{ $tagTones[$edition->action()] ?? 'sand' }}" style="--tilt: {{ $i % 2 ? '-3deg' : '3deg' }}">{{ ucfirst($edition->action()) }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: what changed --}}
    <section class="page page--right" style="--ribbon: var(--sun)">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($chosen)
            <div class="ribbon-clear edition-head">
                <h2 class="eyebrow" style="margin: 0; font-weight: 400">{{ $isCurrent ? __('The latest edition') : 'Edition '.$chosenNumber }} · {{ $chosen->date()?->format('j M Y, g:i a') }}</h2>
                <div class="edition-head__controls">
                    <label class="edition-head__with">
                        <span>{{ __('Compared with') }}</span>
                        <select wire:change="compareWith($event.target.value)" aria-label="{{ __('Compare this edition with') }}">
                            <option value="previous" @selected($againstNow === 'previous')>{{ __('The edition before it') }}</option>
                            <option value="now" @selected($againstNow === 'now')>{{ __('What’s in the editor now') }}</option>
                            @foreach ($this->editions as $i => $edition)
                                @continue($edition->id() === $chosen->id())
                                <option value="{{ $edition->id() }}" @selected($againstNow === $edition->id())>{{ $i === 0 ? __('The latest edition') : 'Edition '.($count - $i) }} · {{ $edition->date()?->format('j M, g:ia') }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="edition-head__view" role="group" aria-label="{{ __('How to show the changes') }}">
                        <button type="button" class="pill" wire:click="setView('inline')" aria-pressed="{{ $view === 'inline' ? 'true' : 'false' }}">{{ __('One column') }}</button>
                        <button type="button" class="pill" wire:click="setView('side')" aria-pressed="{{ $view === 'side' ? 'true' : 'false' }}">{{ __('Side by side') }}</button>
                    </div>
                </div>
                <p class="edition-head__stats">
                    @if ($hasOlder)
                        {!! __('From :older to :newer:', ['older' => '<strong>'.e($olderName).'</strong>', 'newer' => '<strong>'.e($newerName).'</strong>']) !!}
                        <mark class="diff-add">{{ trans_choice(':count word added|:count words added', $stats['added']) }}</mark>,
                        <del class="diff-del">{{ __(':removed removed', ['removed' => $stats['removed']]) }}</del>{{ $changes ? ', '.trans_choice('and :count other change|and :count other changes', count($changes)) : '' }}.
                    @else
                        The first edition: everything in it is new.
                    @endif
                </p>
            </div>

            @if ($chosen->message())
                <p class="saved-note" style="margin-top: 14px">{{ $chosen->message() }}</p>
            @endif

            @if ($view === 'side' && $hasOlder)
                <div class="diff-side">
                    <div>
                        <p class="eyebrow diff-side__head">{{ $olderName }}</p>
                        <div class="serif diff diff--side">
                            @forelse ($before as $p => $paragraph)
                                <p @class(['diff__title' => $p === 0])>@foreach ($paragraph as [$type, $text])@if ($type === 'removed')<del class="diff-del">{{ __($text) }}</del>@else{{ __($text) }}@endif @endforeach</p>
                            @empty
                                <p class="muted" style="font-style: italic">{{ __('No words.') }}</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <p class="eyebrow diff-side__head">{{ $newerName }}</p>
                        <div class="serif diff diff--side">
                            @forelse ($after as $p => $paragraph)
                                <p @class(['diff__title' => $p === 0])>@foreach ($paragraph as [$type, $text])@if ($type === 'added')<mark class="diff-add">{{ __($text) }}</mark>@else{{ __($text) }}@endif @endforeach</p>
                            @empty
                                <p class="muted" style="font-style: italic">{{ __('No words.') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @else
                <div class="serif diff" style="margin-top: 26px; max-width: 660px; font-size: 20px; line-height: 1.75; color: var(--ink-2)">
                    @forelse ($paragraphs as $p => $paragraph)
                        <p @class(['diff__title' => $p === 0])>@foreach ($paragraph as [$type, $text])@if ($type === 'added')<mark class="diff-add">{{ __($text) }}</mark>@elseif ($type === 'removed')<del class="diff-del">{{ __($text) }}</del>@else{{ __($text) }}@endif @endforeach</p>
                    @empty
                        <p class="muted" style="font-style: italic">{{ __('This edition has no words in it.') }}</p>
                    @endforelse
                </div>
            @endif

            @if ($hasOlder)
                <div class="edition-fields">
                    <h3 class="eyebrow section-label">{{ __('Everything else') }}</h3>
                    @if ($changes === [])
                        <p class="field__hint" style="margin: 0">{{ __('Nothing else changed: same standfirst, tags, pictures, address and date.') }}</p>
                    @else
                        <ul>
                            @foreach ($changes as $row)
                                <li wire:key="field-{{ $row['handle'] }}" @class(['edition-fields__row', 'edition-fields__row--long' => $row['long']])>
                                    <span class="edition-fields__label">{{ __($row['label']) }}</span>
                                    <span class="edition-fields__values">
                                        <span><span class="sr-only">{{ __('Before:') }} </span>@if ($row['before'] !== '')<del class="diff-del">{{ \Illuminate\Support\Str::limit($row['before'], 400) }}</del>@else<em class="muted">{{ __('empty') }}</em>@endif</span>
                                        <span class="edition-fields__arrow" aria-hidden="true">→</span>
                                        <span><span class="sr-only">{{ __('After:') }} </span>@if ($row['after'] !== '')<mark class="diff-add">{{ \Illuminate\Support\Str::limit($row['after'], 400) }}</mark>@else<em class="muted">{{ __('empty') }}</em>@endif</span>
                                    </span>
                                    @if ($row['can_put_back'])
                                        <button type="button" class="link-button edition-fields__back" wire:click="putBack(@js($row['handle']))"
                                                wire:confirm="Put back the {{ mb_strtolower($row['label']) }} from {{ $isCurrent ? 'the latest edition' : 'edition '.$chosenNumber }}? Everything else stays as it is now.">{{ __('Put back :value', ['value' => $isCurrent ? 'this' : 'edition '.$chosenNumber.'’s']) }}</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div style="margin-top: auto; padding-top: 28px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
                    @if (! $differsFromNow)
                        <span class="btn" style="cursor: default; background: var(--edge-1); border-color: transparent">{{ __('This is what’s in the editor') }}</span>
                    @else
                        <button type="button" class="btn btn--ink" wire:click="restore"
                                wire:confirm="Bring back {{ $isCurrent ? 'the latest edition' : 'edition '.$chosenNumber }}, all of it? What’s in the editor now is kept as an edition too.">{{ __('Restore all of :value', ['value' => $isCurrent ? 'it' : 'edition '.$chosenNumber]) }}</button>
                    @endif
                    <a href="{{ route('admin.entries.edit', [$collection, $entryId]) }}" class="btn btn--quiet" wire:navigate>{{ __('Back to the editor') }}</a>
                </div>
                <x-admin.folio side="right" style="margin: 0; padding: 0" />
            </div>
        @else
            <p class="empty-note" style="max-width: 460px">{{ __('Save the entry once and its first edition appears here.') }}</p>
        @endif
    </section>
</div>
