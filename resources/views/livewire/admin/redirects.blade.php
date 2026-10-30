<div style="display: contents">
    {{-- Left page: what redirects are, and adding one --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.menus') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Site menus') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Redirects') }}</h1>
            </div>
            <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('When a page’s address changes, its old one forwards to the new one, so shared links and search results keep working. Booklet adds these by itself.') }}</p>
        </div>

        <dl class="redirect-totals">
            <div><dt class="eyebrow">{{ __('Redirects') }}</dt><dd>{{ number_format($total) }}</dd></div>
            <div><dt class="eyebrow">{{ __('Readers forwarded') }}</dt><dd>{{ number_format($hits) }}</dd></div>
        </dl>

        <form wire:submit="add" class="redirect-add">
            <h2 class="heading heading--sub">{{ __('Add one yourself') }}</h2>
            <p class="field__hint" style="margin-top: -6px">{{ __('For links from an old website, or a page you’ve replaced.') }}</p>
            <div class="field">
                <label for="redirect-from">{{ __('Old address') }}</label>
                <input id="redirect-from" type="text" wire:model="from" placeholder="{{ __('/old-page') }}" autocomplete="off" spellcheck="false">
                @error('from') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="redirect-to">{{ __('Goes to') }}</label>
                <input id="redirect-to" type="text" x-data x-on:focus-redirect-to.window="$nextTick(() => $el.focus())" wire:model="to" placeholder="{{ __('/new-page or https://…') }}" autocomplete="off" spellcheck="false">
                @error('to') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <label class="redirect-add__temp">
                <input type="checkbox" wire:model="temporary">
                <span>{{ __('Just for now') }} <small>{{ __('(a temporary redirect, so search engines keep the old address)') }}</small></span>
            </label>
            <button type="submit" class="btn btn--ink" style="align-self: flex-start">{{ __('Add redirect') }}</button>
        </form>

        {{-- Links in published posts and pages that lead nowhere (checked weekly) --}}
        <div class="link-check" id="broken-links">
            <div class="section-head" style="margin: 0">
                <h2 class="heading heading--sub" style="margin: 0">{{ __('Broken links') }}</h2>
                <button type="button" class="btn btn--sm" wire:click="checkLinks" wire:loading.attr="disabled" wire:target="checkLinks">
                    <span wire:loading.remove wire:target="checkLinks">{{ __('Check now') }}</span>
                    <span wire:loading wire:target="checkLinks">{{ __('Checking…') }}</span>
                </button>
            </div>
            @if (! $linkReport)
                <p class="field__hint">{{ __('Every link in your published posts and pages is checked once a week. Nothing checked yet.') }}</p>
            @else
                <p class="field__hint">
                    {{ trans_choice(':count link checked|:count links checked', $linkReport['links'], ['count' => number_format($linkReport['links'])]) }}
                    {{ \Illuminate\Support\Carbon::parse($linkReport['checked_at'])->diffForHumans() }}.
                    @if ($linkReport['broken'] === []) All of them lead somewhere. @endif
                </p>
                @if ($linkReport['broken'] !== [])
                    <ul class="link-check__list">
                        @foreach (collect($linkReport['broken'])->take(30) as $i => $bad)
                            <li wire:key="bad-{{ $i }}-{{ md5($bad['url']) }}">
                                <span class="link-check__url" title="{{ $bad['url'] }}">{{ \Illuminate\Support\Str::limit(preg_replace('#^https?://(www\.)?#', '', $bad['url']), 60) }}</span>
                                <span class="link-check__why">{{ __('In “:value”: :reason.', ['value' => \Illuminate\Support\Str::limit($bad['title'], 50), 'reason' => $bad['reason']]) }}</span>
                                <span class="link-check__do">
                                    <a href="{{ route('admin.entries.edit', [$bad['collection'] ?? 'posts', $bad['entry_id']]) }}" wire:navigate>{{ __('Fix the link') }}</a>
                                    @if ($bad['internal'] && $bad['path'])
                                        <button type="button" class="link-button" wire:click="forward(@js($bad['path']))">{{ __('Forward it') }}</button>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if (count($linkReport['broken']) > 30)
                        <p class="field__hint">{{ __('…and :count more.', ['count' => count($linkReport['broken']) - 30]) }}</p>
                    @endif
                @endif
            @endif
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the list --}}
    <section class="page page--right page--stack" style="--ribbon: var(--mint)">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap">
            <h2 class="heading" style="flex: 1 1 auto">{{ __('Old → new') }}</h2>
            <label for="redirect-search" class="sr-only">{{ __('Find a redirect') }}</label>
            <input id="redirect-search" type="search" class="input" wire:model.live.debounce.250ms="search" placeholder="{{ __('Find an address…') }}" style="max-width: 220px; min-height: 38px">
        </div>

        @if ($redirects->isEmpty())
            <div class="bin-empty">
                <p class="serif">{{ $search !== '' ? __('No redirect matches that.') : __('No redirects yet.') }}</p>
                <p>{{ __('Change a published page’s address and its old one will show up here.') }}</p>
            </div>
        @else
            <ul class="redirect-list">
                @foreach ($redirects as $r)
                    @php
                        $internal = ! str_starts_with($r->to_path, 'http');
                        $hidden = $live->has($r->from_path);
                        $nowhere = $internal && $r->to_path !== '/' && ! $live->has($r->to_path);
                    @endphp
                    <li wire:key="redirect-{{ $r->id }}">
                        <span class="redirect-list__paths">
                            <code>{{ $r->from_path }}</code>
                            <span class="redirect-list__arrow" aria-label="{{ __('goes to') }}">→</span>
                            <code>{{ $r->to_path }}</code>
                        </span>
                        <span class="redirect-list__meta">
                            <span class="sticker {{ $r->automatic ? 'sticker--mint' : 'sticker--sand' }}" style="--tilt: 0deg; font-size: 11px; padding: 2px 8px">{{ $r->automatic ? __('Automatic') : __('Added by hand') }}</span>
                            @if ($r->status === 302) <span class="sticker sticker--sky" style="--tilt: 0deg; font-size: 11px; padding: 2px 8px">{{ __('For now') }}</span> @endif
                            {{ trans_choice(':count reader|:count readers', $r->hits, ['count' => number_format($r->hits)]) }}{{ $r->last_hit_at ? ', '.__('last :when', ['when' => $r->last_hit_at->diffForHumans()]) : '' }}
                        </span>
                        @if ($hidden)
                            <span class="redirect-list__warn">{{ __('A page lives at the old address now, so this one isn’t used.') }}</span>
                        @elseif ($nowhere)
                            <span class="redirect-list__warn">{{ __('Nothing is published at the new address. It may be in the bin, or a draft.') }}</span>
                        @endif
                        <span class="redirect-list__do">
                            <a href="{{ url($r->from_path) }}" target="_blank" rel="noopener" class="link-button">{{ __('Try it ↗') }}</a>
                            <button type="button" class="link-button bin-list__forget" wire:click="remove({{ $r->id }})"
                                    wire:confirm="Stop forwarding {{ $r->from_path }}? People following old links will see “page not found”.">{{ __('Remove') }}</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
