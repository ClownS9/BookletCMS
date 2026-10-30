<div style="display: contents">
    {{-- Left page: the list (or, for Pages, the tree) --}}
    <section class="page page--left" style="flex-basis: 420px">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <p class="eyebrow">{{ \App\Support\AdminNav::chapterLabel() }} · {{ $structured ? trans_choice(':count page|:count pages', $total) : trans_choice(':count entry|:count entries', $total) }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __($model->title()) }}</h1>
            </div>
            <a href="{{ route('admin.entries.create', $model->handle()) }}" class="btn btn--ink has-handheld-twin" wire:navigate>
                <x-admin.icon name="plus" size="16" /> {{ __('New') }}
            </a>
        </div>

        @if ($structured)
            <p style="margin: 18px 0 0; font-size: 14px; color: var(--muted)">
                {{ __('Your site’s pages, nested the way they appear on the site.') }}
                @if ($canArrange) <a href="{{ route('admin.menus') }}" wire:navigate>{{ __('Edit site menus →') }}</a> @endif
            </p>
        @else
            <div class="has-handheld-twin" style="margin-top: 10px; display: flex; gap: 18px; flex-wrap: wrap">
                @if ($hasTaxonomies)
                    <a href="{{ route('admin.taxonomies') }}" class="back" wire:navigate>{{ __('Categories & tags →') }}</a>
                @endif
                @if ($model->dated())
                    <a href="{{ route('admin.calendar') }}" class="back" wire:navigate>{{ __('Calendar →') }}</a>
                @endif
                <a href="{{ route('admin.print', ['collection' => $model->handle()]) }}" class="back" wire:navigate>{{ __('Print as a book →') }}</a>
            </div>
        @endif
        <x-admin.insert-links :chapter="$model->title()" />

        <div class="search" style="margin-top: 16px">
            <x-admin.icon name="search" size="16" />
            <label for="entry-search" class="sr-only">{{ __('Search :what', ['what' => $model->title()]) }}</label>
            <input id="entry-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('Search this chapter') }}">
        </div>

        <div class="filters has-handheld-twin" role="group" aria-label="{{ __('Filter by status') }}" style="margin: 16px 0 12px">
            @foreach ($statuses as $value => $label)
                <button type="button" class="pill" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">
                    {{ __($label) }}@if ($value !== 'all') · {{ $counts[$value] ?? 0 }}@endif
                </button>
            @endforeach
        </div>

        @if ($rows === [])
            <p class="empty-note">
                @if ($search !== '' || $status !== 'all')
                    {{ __('Nothing matches on this page. Try another filter.') }}
                @else
                    {{ __('Nothing in :what yet.', ['what' => $model->title()]) }} <a href="{{ route('admin.entries.create', $model->handle()) }}" wire:navigate>{{ __('Write the first one →') }}</a>
                @endif
            </p>
        @else
            <label class="check ticks__all">
                <input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked(array_diff($pageIds, $ticked) === [])>
                {{ $lastPage > 1 ? __('Choose all on this page') : __('Choose all') }}
            </label>
            <ul class="picks" @if ($structured) role="tree" aria-label="{{ __('Page tree') }}" @endif>
                @foreach ($rows as $i => $row)
                    @php $entry = $row['entry']; @endphp
                    <li wire:key="entry-{{ $entry->id() }}" class="picks__row" @if ($structured) role="treeitem" aria-level="{{ $row['depth'] + 1 }}" @endif>
                        <input type="checkbox" class="pick-tick" wire:model.live="ticked" value="{{ $entry->id() }}" aria-label="{{ __('Choose “:name”', ['name' => $entry->title() ?: __('Untitled')]) }}">
                        <button type="button" @class(['pick', 'pick--tree' => $structured]) wire:click="pick('{{ $entry->id() }}')" aria-pressed="{{ $chosen?->id() === $entry->id() ? 'true' : 'false' }}">
                            @if ($structured && $row['depth'] > 0)
                                <span class="pick__indent" style="--depth: {{ $row['depth'] }}" aria-hidden="true"></span>
                            @endif
                            <span class="dot" style="--dot: var(--{{ ['draft' => 'sun', 'scheduled' => 'sky', 'published' => 'mint'][$entry->status()] }}); --tilt: {{ $i % 2 ? '-6deg' : '6deg' }}"></span>
                            @if ($structured)
                                <span class="pick__title" style="flex: 1 1 auto; min-width: 0">{{ $entry->title() ?: __('Untitled') }}@if ($entry->get('translation_of'))<span class="pick__lang" title="{{ __('Translation') }}">{{ strtoupper((string) $entry->get('lang')) }}</span>@endif@if ($n = $noteCounts[$entry->id()] ?? 0)<span class="pick__notes" title="{{ trans_choice(':count sticky note|:count sticky notes', $n) }}">{{ $n }}</span>@endif</span>
                                <span class="pick__path">{{ $entry->uri() ?? '—' }}</span>
                            @else
                                <span style="min-width: 0">
                                    <span class="pick__title">{{ $entry->title() ?: __('Untitled') }}@if ($entry->get('translation_of'))<span class="pick__lang" title="{{ __('Translation') }}">{{ strtoupper((string) $entry->get('lang')) }}</span>@endif@if ($n = $noteCounts[$entry->id()] ?? 0)<span class="pick__notes" title="{{ trans_choice(':count sticky note|:count sticky notes', $n) }}">{{ $n }}</span>@endif</span>
                                    <span class="pick__meta">
                                        {{ __(ucfirst($entry->status())) }}@if ($r = $reviewStates[$entry->id()] ?? null) · {{ $r === 'waiting' ? __('in review') : __('sent back') }}@endif
                                        · {{ $entry->date()?->translatedFormat('j M Y') ?? ($entry->lastModified() ? \Illuminate\Support\Carbon::parse($entry->lastModified())->translatedFormat('j M Y') : '') }}
                                    </span>
                                </span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($ticked !== [])
                <div class="ticks" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                    <div class="ticks__head">
                        <strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="untickAll">{{ __('Clear') }}</button>
                    </div>
                    <div class="ticks__do">
                        @if ($canArrange)
                            <button type="button" class="btn btn--sm" wire:click="publishTicked">{{ __('Publish') }}</button>
                            <button type="button" class="btn btn--sm" wire:click="draftTicked">{{ __('Make drafts') }}</button>
                        @endif
                        <button type="button" class="btn btn--danger btn--sm" wire:click="binTicked" wire:confirm="{{ trans_choice('Move :count to the bin? It waits there for 30 days.|Move :count to the bin? They wait there for 30 days.', count($ticked)) }}">{{ __('Move to the bin') }}</button>
                    </div>
                    @if ($termFields !== [])
                        <form class="ticks__term" wire:submit="termTicked">
                            @if (count($termFields) > 1)
                                <label for="bulk-field" class="sr-only">{{ __('Add to') }}</label>
                                <select id="bulk-field" class="input" wire:model.live="bulkField">
                                    @foreach ($termFields as $handle => $f)
                                        <option value="{{ $handle }}">{{ __($f['label']) }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <label for="bulk-words" class="sr-only">{{ __($termFields[$bulkField]['label'] ?? 'Tags') }}</label>
                            <input id="bulk-words" class="input" type="text" wire:model="bulkWords" list="bulk-terms" maxlength="80" autocomplete="off"
                                placeholder="{{ __('Type or choose…') }}">
                            <datalist id="bulk-terms">
                                @foreach ($termTitles as $t)
                                    <option value="{{ $t }}"></option>
                                @endforeach
                            </datalist>
                            <button type="submit" class="btn btn--sm">{{ __('Add') }}</button>
                        </form>
                        @error('bulkWords') <p class="field__error" style="margin: 6px 0 0">{{ $message }}</p> @enderror
                    @endif
                </div>
            @endif
            @if ($lastPage > 1)
                <nav class="picks-pager" aria-label="{{ __('Pages of :what', ['what' => $model->title()]) }}">
                    <button type="button" class="btn btn--quiet btn--sm" wire:click="turnTo({{ $page - 1 }})" @disabled($page <= 1)>{{ __('← Newer') }}</button>
                    <span class="field__hint" style="margin: 0">{{ __('Page :n of :count', ['n' => $page, 'count' => $lastPage]) }} · {{ trans_choice(':count entry|:count entries', $shown) }}</span>
                    <button type="button" class="btn btn--quiet btn--sm" wire:click="turnTo({{ $page + 1 }})" @disabled($page >= $lastPage)>{{ __('Older →') }}</button>
                </nav>
            @endif
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the chosen entry --}}
    <section class="page page--right" style="--ribbon: {{ $model->get('sticker', $structured ? 'var(--mint)' : 'var(--sun)') }}">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($chosen)
            <span style="display: flex; flex-wrap: wrap; gap: 8px; align-self: flex-start">
                <span class="sticker sticker--lg sticker--{{ $chosen->status() }}">{{ __(ucfirst($chosen->status())) }}</span>
                @if ($r = $reviewStates[$chosen->id()] ?? null)
                    <span class="sticker sticker--lg sticker--{{ $r === 'waiting' ? 'sky' : 'rose' }}" style="--tilt: 2deg">{{ $r === 'waiting' ? __('In review') : __('Sent back') }}</span>
                @endif
            </span>
            <h2 class="display" style="margin: 20px 0 8px; max-width: 600px">{{ $chosen->title() ?: __('Untitled') }}</h2>

            @if ($structured)
                <p style="font-family: var(--mono); font-size: 14px; color: var(--faint); overflow-wrap: anywhere">{{ parse_url(url('/'), PHP_URL_HOST) }}{{ $chosen->uri() ?? '' }}</p>

                <h3 class="field__label" style="margin: 30px 0 12px">{{ __('Template') }}</h3>
                <div class="templates" role="radiogroup" aria-label="{{ __('Template') }}">
                    @php $current = (string) $chosen->get('template', 'default'); @endphp
                    @foreach ($templates as $value => [$label, $hint])
                        <button type="button" role="radio" class="template" aria-checked="{{ $current === $value ? 'true' : 'false' }}"
                                wire:click="setTemplate('{{ $value }}')" @disabled(! $canEditChosen)>
                            <span class="template__thumb template__thumb--{{ $value }}" aria-hidden="true"><i></i><i></i><i></i></span>
                            <span class="template__label">{{ __($label) }}</span>
                            <span class="template__hint">{{ __($hint) }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="setting-row" style="margin-top: 24px">
                    <span>
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Show in site menu') }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Appears in the header navigation') }}</span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-checked="{{ $inMenu ? 'true' : 'false' }}" aria-label="{{ __('Show in site menu') }}"
                            wire:click="toggleMenu" @disabled(! $canArrange)></button>
                </div>

                <dl class="facts">
                    <div>
                        <dt class="eyebrow">{{ __('Parent') }}</dt>
                        <dd>
                            @if ($canArrange)
                                <label for="page-parent" class="sr-only">{{ __('Parent page') }}</label>
                                <select id="page-parent" class="input" style="min-height: 36px" wire:change="moveUnder($event.target.value)">
                                    <option value="">{{ __('— Top level') }}</option>
                                    @foreach ($parentChoices as $choice)
                                        <option value="{{ $choice->id() }}" @selected($parentEntry?->id() === $choice->id())>{{ $choice->title() ?: __('Untitled') }}</option>
                                    @endforeach
                                </select>
                            @else
                                {{ $parentEntry?->title() ?? '—' }}
                            @endif
                        </dd>
                    </div>
                    <div><dt class="eyebrow">{{ __('Author') }}</dt><dd>{{ $author?->name ?? '—' }}</dd></div>
                    <div><dt class="eyebrow">{{ __('Edited') }}</dt><dd>{{ $chosen->lastModified() ? \Illuminate\Support\Carbon::parse($chosen->lastModified())->diffForHumans() : '—' }}</dd></div>
                    @if ($chosenReading)
                        <div><dt class="eyebrow">{{ __('Read') }}</dt><dd>{{ trans_choice(':count view in 30 days|:count views in 30 days', $chosenReading['views'], ['count' => number_format($chosenReading['views'])]) }}</dd></div>
                    @endif
                </dl>
            @else
                <p class="serif muted" style="font-style: italic; font-size: 17px">
                    {{ $author ? __('by :name', ['name' => $author->name]).' · ' : '' }}{{ trans_choice(':count word|:count words', $n = str_word_count(strip_tags((string) $chosen->get('content', ''))), ['count' => $n]) }}
                    @if ($chosen->date()) · {{ $chosen->date()->translatedFormat('j F Y') }} @endif
                </p>

                <div class="cover-preview">
                    @if ($cover)
                        <img src="{{ $cover }}" alt="" style="object-position: {{ \App\Support\Focus::forPath(((array) $chosen->get('cover', []))[0] ?? null) }}">
                    @else
                        <span><x-admin.icon name="image" size="20" /> {{ __('No cover picture yet') }}</span>
                    @endif
                </div>

                @if ($excerpt)
                    <p class="serif" style="margin-top: 24px; max-width: 620px; font-size: 19px; line-height: 1.65; color: var(--ink-2)">{{ $excerpt }}</p>
                @endif

                <dl class="facts">
                    <div><dt class="eyebrow">{{ __('Address') }}</dt><dd style="font-family: var(--mono); font-size: 13px; overflow-wrap: anywhere">{{ $chosen->uri() ?? '—' }}</dd></div>
                    <div><dt class="eyebrow">{{ __('Tags') }}</dt><dd>{{ $tags ? implode(', ', $tags) : '—' }}</dd></div>
                    <div><dt class="eyebrow">{{ __('Edited') }}</dt><dd>{{ $chosen->lastModified() ? \Illuminate\Support\Carbon::parse($chosen->lastModified())->diffForHumans() : '—' }} · {{ trans_choice(':count edition|:count editions', $editions) }}</dd></div>
                    @if ($chosenReading)
                        <div><dt class="eyebrow">{{ __('Read') }}</dt><dd>{{ trans_choice(':count view in 30 days|:count views in 30 days', $chosenReading['views'], ['count' => number_format($chosenReading['views'])]) }} @if ($chosenReading['views'] >= 5) · {{ __(':percent% to the end', ['percent' => (int) round(min(1, $chosenReading['reads'] / $chosenReading['views']) * 100)]) }} @endif</dd></div>
                    @endif
                </dl>
            @endif

            <div style="margin-top: auto; padding-top: 32px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
                    @if ($canEditChosen)
                        <a href="{{ route('admin.entries.edit', [$model->handle(), $chosen->id()]) }}" class="btn btn--ink" wire:navigate>{{ $structured ? __('Edit page') : __('Edit') }}</a>
                    @endif
                    @if ($structured)
                        <a href="{{ route('admin.entries.create', [$model->handle(), 'parent' => $chosen->id()]) }}" class="btn" wire:navigate>{{ __('Add sub-page') }}</a>
                        @if ($canArrange)
                            <button type="button" class="icon-btn" wire:click="nudge(-1)" aria-label="{{ __('Move up') }}"><span aria-hidden="true">↑</span></button>
                            <button type="button" class="icon-btn" wire:click="nudge(1)" aria-label="{{ __('Move down') }}"><span aria-hidden="true">↓</span></button>
                        @endif
                    @endif
                    <a href="{{ route('preview', $chosen->id()) }}" class="btn" target="_blank" rel="noopener">{{ __('Preview') }}</a>
                    @if ($chosen->uri() && $chosen->status() === 'published')
                        <a href="{{ url($chosen->uri()) }}" class="btn btn--quiet" target="_blank" rel="noopener">{{ __('View on site ↗') }}</a>
                    @endif
                </div>
                <x-admin.folio side="right" style="margin: 0; padding: 0" />
            </div>
            @unless ($canEditChosen)
                <p class="field__hint" style="margin-top: 10px">{{ __('Only its author or an editor can change it.') }}</p>
            @endunless
        @else
            <p class="empty-note" style="max-width: 460px">{{ __('Pick an entry on the left to see it here.') }}</p>
        @endif
    </section>
</div>
