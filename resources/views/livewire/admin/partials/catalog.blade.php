{{--
    Finding themes or inserts on GitHub (repositories with the topic
    booklet-theme or booklet-insert). Expects $kind: 'theme' or 'insert'.
    "Look inside" unpacks one to one side, as pasting its name would.
--}}
@php
    $found = $this->findResults($kind);
    $topic = \App\Support\Sharing::TOPICS[$kind];
@endphp
<section class="catalog" aria-labelledby="catalog-title-{{ __($kind) }}">
    <div class="catalog__head">
        <h3 id="catalog-title-{{ __($kind) }}" class="catalog__title">{{ __('Find :value on GitHub', ['value' => $kind === 'theme' ? 'themes' : 'inserts']) }}</h3>
        <a href="https://github.com/topics/{{ $topic }}" target="_blank" rel="noopener" class="field__hint" style="margin: 0">{{ __('All on GitHub') }} <span aria-hidden="true">↗</span></a>
    </div>
    <div class="catalog__tools">
        <label for="catalog-find-{{ __($kind) }}" class="sr-only">{{ __('Search') }}</label>
        <input id="catalog-find-{{ __($kind) }}" type="search" class="input" wire:model.live.debounce.500ms="find" placeholder="Search: {{ $kind === 'theme' ? 'dark, magazine…' : 'events, shop…' }}" autocomplete="off">
        <label for="catalog-sort-{{ __($kind) }}" class="sr-only">{{ __('Order') }}</label>
        <select id="catalog-sort-{{ __($kind) }}" class="input catalog__sort" wire:model.live="findSort">
            @foreach (\App\Support\Catalog::SORTS as $value => $label) <option value="{{ $value }}">{{ __($label) }}</option> @endforeach
        </select>
    </div>

    <div class="catalog__results" wire:loading.class="is-loading" wire:target="find, findSort, findPageTo, findAgain">
        @if ($found['error'])
            <p class="vc-note vc-note--warn" style="margin: 0">{{ $found['error'] }}</p>
        @elseif ($found['items'] === [])
            <p class="empty-note" style="margin: 0">
                {{ $this->find !== '' ? 'Nothing found for “'.$this->find.'”.' : __('None yet.') }}
                {{ $kind === 'theme' ? __('Themes') : __('Inserts') }} show up here when their GitHub repository is public and has the topic <code>{{ $topic }}</code>
                {{ __('(GitHub can take a few minutes to notice a new one).') }}
                <button type="button" class="link-button" wire:click="findAgain" wire:loading.attr="disabled" wire:target="findAgain">{{ __('Look again') }}</button>
            </p>
        @else
            <ul class="catalog__list">
                @foreach ($found['items'] as $item)
                    @php $here = \App\Support\Catalog::here($kind, $item); $fits = \App\Support\Catalog::fits($item); @endphp
                    <li class="catalog-item" wire:key="found-{{ $item['repo'] }}">
                        <span class="catalog-item__pic" aria-hidden="true">
                            @if ($item['preview'])
                                <img src="{{ $item['preview'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                            @else
                                <span class="catalog-item__letter">{{ mb_strtoupper(mb_substr($item['name'], 0, 1)) }}</span>
                            @endif
                        </span>
                        <span class="catalog-item__words">
                            <span class="catalog-item__name">{{ $item['name'] }} @if ($item['version']) <span class="muted">{{ $item['version'] }}</span> @endif</span>
                            <span class="catalog-item__meta">
                                @if ($item['author']) by {{ $item['author'] }} · @endif
                                ★ {{ number_format($item['stars']) }}
                                @if ($item['updated']) · updated {{ \Illuminate\Support\Carbon::parse($item['updated'])->diffForHumans() }} @endif
                            </span>
                            @if ($item['about']) <span class="catalog-item__about">{{ __($item['about']) }}</span> @endif
                            <span class="catalog-item__tags">
                                @if ($here && $here['newer']) <span class="catalog-tag catalog-tag--new">{{ __('Newer than yours (:version)', ['version' => $here['version']]) }}</span>
                                @elseif ($here) <span class="catalog-tag">{{ __('On your shelf') }}</span> @endif
                                @if (! $fits) <span class="catalog-tag catalog-tag--warn">{{ __('Needs Booklet :needs', ['needs' => $item['needs']]) }}</span> @endif
                                @if ($kind === 'insert') <span class="catalog-tag" title="{{ __('Adding code asks for your password (unless you gave it in the last few hours).') }}">{{ __('Code: needs your password') }}</span>
                                @elseif ($item['scripts']) <span class="catalog-tag" title="{{ __('Adding code asks for your password (unless you gave it in the last few hours).') }}">{{ __('Has scripts: needs your password') }}</span> @endif
                                @if (! $item['readable']) <span class="catalog-tag catalog-tag--warn">{{ __('No :value found', ['value' => $kind === 'theme' ? 'theme.json' : 'insert.php']) }}</span> @endif
                            </span>
                        </span>
                        <span class="catalog-item__do">
                            <button type="button" class="btn btn--sm" wire:click="lookAt('{{ $item['repo'] }}')" wire:loading.attr="disabled" wire:target="lookAt('{{ $item['repo'] }}')" @disabled(! $fits || ! $item['readable'])>
                                <span wire:loading.remove wire:target="lookAt('{{ $item['repo'] }}')">{{ $here && $here['newer'] ? __('Look at the update') : __('Look inside') }}</span>
                                <span wire:loading wire:target="lookAt('{{ $item['repo'] }}')">{{ __('Fetching…') }}</span>
                            </button>
                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="catalog-item__github">{{ __('GitHub') }} <span aria-hidden="true">↗</span></a>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="catalog__when">
                Found {{ \Illuminate\Support\Carbon::parse($found['at'])->diffForHumans() }}.
                <button type="button" class="link-button" wire:click="findAgain" wire:loading.attr="disabled" wire:target="findAgain">{{ __('Look again') }}</button>
            </p>
            @if ($found['page'] > 1 || $found['more'])
                <div class="catalog__pager">
                    <button type="button" class="btn btn--quiet btn--sm" wire:click="findPageTo({{ $found['page'] - 1 }})" @disabled($found['page'] <= 1)>{{ __('← Back') }}</button>
                    <span class="field__hint" style="margin: 0">{{ __('Page :page', ['page' => $found['page']]) }}</span>
                    <button type="button" class="btn btn--quiet btn--sm" wire:click="findPageTo({{ $found['page'] + 1 }})" @disabled(! $found['more'])>{{ __('More →') }}</button>
                </div>
            @endif
        @endif
    </div>
    <p class="field__hint" style="margin: 0">Found on GitHub, so not checked by anyone yet. You’ll see what’s inside before anything is added.{{ $kind === 'theme' ? ' Cover packs are read through by Booklet; themes with templates are code, so only add ones from people you trust.' : ' Inserts are code, so only add ones from people you trust.' }}</p>
</section>
