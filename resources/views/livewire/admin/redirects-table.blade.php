@php
    $pageIds = $redirects->getCollection()->pluck('id')->all();
    $sortMark = fn (string $col) => ltrim($sort, '-') === $col ? (str_starts_with($sort, '-') ? '↓' : '↑') : '↕';
    $ariaSort = fn (string $col) => ltrim($sort, '-') === $col ? (str_starts_with($sort, '-') ? 'descending' : 'ascending') : 'none';
@endphp
<div style="display: contents">
    {{-- Redirects in the Dashboard layout: old → new in a sortable table, adding
         one above it, and the weekly broken-links check below. --}}
    <section class="page page--single page--dash" aria-labelledby="redirects-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="redirects-title">{{ __('Redirects') }}</h1>
                    <p>{{ __('When a page’s address changes, its old one forwards to the new one, so shared links and search results keep working. Booklet adds these by itself.') }}</p>
                </div>
                <button type="button" class="btn btn--ink" wire:click="$toggle('adding')" aria-expanded="{{ $adding ? 'true' : 'false' }}"><x-admin.icon name="plus" size="16" /> {{ __('Add redirect') }}</button>
            </div>

            <div class="dash-figures">
                <div class="dash-figure" style="--tone: var(--mint)"><span class="dash-figure__label"><i aria-hidden="true"></i>{{ __('Redirects') }}</span><span class="dash-figure__n"><b>{{ number_format($total) }}</b></span></div>
                <div class="dash-figure" style="--tone: var(--sky)"><span class="dash-figure__label"><i aria-hidden="true"></i>{{ __('Readers forwarded') }}</span><span class="dash-figure__n"><b>{{ number_format($hits) }}</b></span></div>
                <a href="#broken-links" class="dash-figure" style="--tone: var(--coral)"><span class="dash-figure__label"><i aria-hidden="true"></i>{{ __('Broken links') }}</span><span class="dash-figure__n"><b>{{ $linkReport ? number_format(count($linkReport['broken'])) : '—' }}</b></span></a>
            </div>

            @if ($adding)
                <form wire:submit="add" class="dash-card dash-invite" aria-label="{{ __('Add one yourself') }}">
                    <div class="field">
                        <label for="redirect-from">{{ __('Old address') }}</label>
                        <input id="redirect-from" type="text" class="input" wire:model="from" placeholder="{{ __('/old-page') }}" autocomplete="off" spellcheck="false">
                        @error('from') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="redirect-to">{{ __('Goes to') }}</label>
                        <input id="redirect-to" type="text" class="input" x-data x-init="$nextTick(() => { if ($wire.from) $el.focus() })" x-on:focus-redirect-to.window="$nextTick(() => $el.focus())" wire:model="to" placeholder="{{ __('/new-page or https://…') }}" autocomplete="off" spellcheck="false">
                        @error('to') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <label class="redirect-add__temp" style="align-self: center">
                        <input type="checkbox" wire:model="temporary">
                        <span>{{ __('Just for now') }} <small>{{ __('(a temporary redirect, so search engines keep the old address)') }}</small></span>
                    </label>
                    <div class="dash-invite__go">
                        <button type="submit" class="btn btn--ink">{{ __('Add redirect') }}</button>
                        <button type="button" class="btn btn--quiet" wire:click="$set('adding', false)">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif

            <section class="dash-card" aria-label="{{ __('Old → new') }}">
                <div class="dash-tabs" role="group" aria-label="{{ __('Show') }}">
                    @foreach (['' => 'All', 'auto' => 'Automatic', 'hand' => 'Added by hand'] as $value => $label)
                        <button type="button" wire:click="$set('kind', '{{ $value }}')" aria-pressed="{{ $kind === $value ? 'true' : 'false' }}">{{ __($label) }} <b>{{ number_format($kindCounts[$value]) }}</b></button>
                    @endforeach
                </div>
                @if ($ticked === [])
                    <div class="dash-tools">
                        <div class="search">
                            <x-admin.icon name="search" size="16" />
                            <label for="redirect-search" class="sr-only">{{ __('Find a redirect') }}</label>
                            <input id="redirect-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('Find an address…') }}">
                        </div>
                    </div>
                @else
                    <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                        <strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
                        <button type="button" class="btn btn--danger btn--sm" wire:click="removeTicked" wire:confirm="{{ trans_choice('Stop forwarding :count address? People following old links will see “page not found”.|Stop forwarding :count addresses? People following old links will see “page not found”.', count($ticked)) }}">{{ __('Remove') }}</button>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('ticked', [])">{{ __('Clear') }}</button>
                    </div>
                @endif

                @if ($redirects->isEmpty())
                    <p class="dash-empty">{{ $search !== '' ? __('No redirect matches that.') : __('No redirects yet. Change a published page’s address and its old one will show up here.') }}</p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="dash-table__tick"><input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked($pageIds !== [] && array_diff($pageIds, $ticked) === []) aria-label="{{ __('Choose all on this page') }}"></th>
                                    <th scope="col" aria-sort="{{ $ariaSort('from') }}"><button type="button" class="dash-table__sort" wire:click="sortBy('from')">{{ __('Old address') }}<span aria-hidden="true">{{ $sortMark('from') }}</span></button></th>
                                    <th scope="col">{{ __('Goes to') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Kind') }}</th>
                                    <th scope="col" class="is-num" aria-sort="{{ $ariaSort('hits') }}"><button type="button" class="dash-table__sort" wire:click="sortBy('hits')">{{ __('Readers') }}<span aria-hidden="true">{{ $sortMark('hits') }}</span></button></th>
                                    <th scope="col" class="is-wide" aria-sort="{{ $ariaSort('used') }}"><button type="button" class="dash-table__sort" wire:click="sortBy('used')">{{ __('Last used') }}<span aria-hidden="true">{{ $sortMark('used') }}</span></button></th>
                                    <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($redirects as $r)
                                    @php
                                        $internal = ! str_starts_with($r->to_path, 'http');
                                        $hidden = $live->has($r->from_path);
                                        $nowhere = $internal && $r->to_path !== '/' && ! $live->has($r->to_path);
                                    @endphp
                                    <tr wire:key="redirect-{{ $r->id }}" @class(['is-ticked' => in_array($r->id, $ticked, true)])>
                                        <td class="dash-table__tick"><input type="checkbox" wire:model.live="ticked" value="{{ $r->id }}" aria-label="{{ __('Choose :from', ['from' => $r->from_path]) }}"></td>
                                        <td class="dash-table__main"><code class="dash-code">{{ $r->from_path }}</code>
                                            @if ($hidden)<span class="dash-table__warn">{{ __('A page lives at the old address now, so this one isn’t used.') }}</span>@endif
                                        </td>
                                        <td><code class="dash-code">{{ $r->to_path }}</code>
                                            @if ($nowhere)<span class="dash-table__warn">{{ __('Nothing is published at the new address. It may be in the bin, or a draft.') }}</span>@endif
                                        </td>
                                        <td class="is-wide">
                                            <span class="dash-tag">{{ $r->automatic ? __('Automatic') : __('Added by hand') }}</span>
                                            @if ($r->status === 302)<span class="dash-tag">{{ __('For now') }}</span>@endif
                                        </td>
                                        <td class="is-num">{{ number_format($r->hits) }}</td>
                                        <td class="is-wide is-quiet">{{ $r->last_hit_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="dash-table__actions">
                                            <a href="{{ url($r->from_path) }}" target="_blank" rel="noopener" class="btn btn--sm btn--quiet">{{ __('Try it ↗') }}</a>
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="remove({{ $r->id }})" wire:confirm="{{ __('Stop forwarding :from? People following old links will see “page not found”.', ['from' => $r->from_path]) }}">{{ __('Remove') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-admin.pager :of="$redirects" :sizes="$pageSizes" class="dash-foot" id="redirects-pager" always />
                @endif
            </section>

            {{-- Links in published posts and pages that lead nowhere (checked weekly) --}}
            <section class="dash-card" id="broken-links" aria-labelledby="broken-title">
                <div class="dash-card__head">
                    <h2 id="broken-title">{{ __('Broken links') }}</h2>
                    @if ($linkReport)<small>{{ trans_choice(':count link checked|:count links checked', $linkReport['links'], ['count' => number_format($linkReport['links'])]) }} · {{ \Illuminate\Support\Carbon::parse($linkReport['checked_at'])->diffForHumans() }}</small>@endif
                    <button type="button" class="btn btn--sm" wire:click="checkLinks" wire:loading.attr="disabled" wire:target="checkLinks">
                        <span wire:loading.remove wire:target="checkLinks">{{ __('Check now') }}</span>
                        <span wire:loading wire:target="checkLinks">{{ __('Checking…') }}</span>
                    </button>
                </div>
                @if (! $linkReport)
                    <p class="dash-empty">{{ __('Every link in your published posts and pages is checked once a week. Nothing checked yet.') }}</p>
                @elseif ($linkReport['broken'] === [])
                    <p class="dash-empty">{{ __('All of them lead somewhere.') }}</p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead><tr><th scope="col">{{ __('Link') }}</th><th scope="col" class="is-wide">{{ __('In') }}</th><th scope="col" class="is-wide">{{ __('Why') }}</th><th scope="col"><span class="sr-only">{{ __('More') }}</span></th></tr></thead>
                            <tbody>
                                @foreach (collect($linkReport['broken'])->take(100) as $i => $bad)
                                    <tr wire:key="bad-{{ $i }}-{{ md5($bad['url']) }}">
                                        <td class="dash-table__main"><span class="dash-code" title="{{ $bad['url'] }}">{{ \Illuminate\Support\Str::limit(preg_replace('#^https?://(www\.)?#', '', $bad['url']), 60) }}</span></td>
                                        <td class="is-wide">{{ \Illuminate\Support\Str::limit($bad['title'], 50) }}</td>
                                        <td class="is-wide is-quiet" style="white-space: normal">{{ $bad['reason'] }}</td>
                                        <td class="dash-table__actions">
                                            <a href="{{ route('admin.entries.edit', [$bad['collection'] ?? 'posts', $bad['entry_id']]) }}" class="btn btn--sm btn--quiet" wire:navigate>{{ __('Fix the link') }}</a>
                                            @if ($bad['internal'] && $bad['path'])
                                                <button type="button" class="btn btn--sm btn--quiet" wire:click="forward(@js($bad['path']))">{{ __('Forward it') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </section>
</div>
