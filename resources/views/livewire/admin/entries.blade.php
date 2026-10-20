<div style="display: contents">
    {{-- Left page: the list (or, for Pages, the tree) --}}
    <section class="page page--left" style="flex-basis: 420px">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <p class="eyebrow">{{ \App\Support\AdminNav::chapterLabel() }} · {{ $total }} {{ str($structured ? 'page' : 'entry')->plural($total) }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ $model->title() }}</h1>
            </div>
            <a href="{{ route('admin.entries.create', $model->handle()) }}" class="btn btn--ink" wire:navigate>
                <x-admin.icon name="plus" size="16" /> New
            </a>
        </div>

        @if ($structured)
            <p style="margin: 18px 0 0; font-size: 14px; color: var(--muted)">
                Your site’s pages, nested the way they appear on the site.
                @if ($canArrange) <a href="{{ route('admin.menus') }}" wire:navigate>Edit site menus →</a> @endif
            </p>
        @else
            <div style="margin-top: 10px; display: flex; gap: 18px; flex-wrap: wrap">
                @if ($hasTaxonomies)
                    <a href="{{ route('admin.taxonomies') }}" class="back" wire:navigate>Categories &amp; tags →</a>
                @endif
                @if ($model->dated())
                    <a href="{{ route('admin.calendar') }}" class="back" wire:navigate>Calendar →</a>
                @endif
                <a href="{{ route('admin.print', ['collection' => $model->handle()]) }}" class="back" wire:navigate>Print as a book →</a>
            </div>
        @endif
        <x-admin.insert-links :chapter="$model->title()" />

        <div class="search" style="margin-top: 16px">
            <x-admin.icon name="search" size="16" />
            <label for="entry-search" class="sr-only">Search {{ $model->title() }}</label>
            <input id="entry-search" type="search" wire:model.live.debounce.250ms="search" placeholder="Search this chapter">
        </div>

        <div class="filters" role="group" aria-label="Filter by status" style="margin: 16px 0 12px">
            @foreach ($statuses as $value => $label)
                <button type="button" class="pill" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">
                    {{ $label }}@if ($value !== 'all') · {{ $counts[$value] ?? 0 }}@endif
                </button>
            @endforeach
        </div>

        @if ($rows === [])
            <p class="empty-note">
                @if ($search !== '' || $status !== 'all')
                    Nothing matches on this page. Try another filter.
                @else
                    Nothing in {{ $model->title() }} yet. <a href="{{ route('admin.entries.create', $model->handle()) }}" wire:navigate>Write the first one →</a>
                @endif
            </p>
        @else
            <ul class="picks" @if ($structured) role="tree" aria-label="Page tree" @endif>
                @foreach ($rows as $i => $row)
                    @php $entry = $row['entry']; @endphp
                    <li wire:key="entry-{{ $entry->id() }}" @if ($structured) role="treeitem" aria-level="{{ $row['depth'] + 1 }}" @endif>
                        <button type="button" @class(['pick', 'pick--tree' => $structured]) wire:click="pick('{{ $entry->id() }}')" aria-pressed="{{ $chosen?->id() === $entry->id() ? 'true' : 'false' }}">
                            @if ($structured && $row['depth'] > 0)
                                <span class="pick__indent" style="--depth: {{ $row['depth'] }}" aria-hidden="true"></span>
                            @endif
                            <span class="dot" style="--dot: var(--{{ ['draft' => 'sun', 'scheduled' => 'sky', 'published' => 'mint'][$entry->status()] }}); --tilt: {{ $i % 2 ? '-6deg' : '6deg' }}"></span>
                            @if ($structured)
                                <span class="pick__title" style="flex: 1 1 auto; min-width: 0">{{ $entry->title() ?: 'Untitled' }}@if ($entry->get('translation_of'))<span class="pick__lang" title="Translation">{{ strtoupper((string) $entry->get('lang')) }}</span>@endif@if ($n = $noteCounts[$entry->id()] ?? 0)<span class="pick__notes" title="{{ $n }} sticky {{ str('note')->plural($n) }}">{{ $n }}</span>@endif</span>
                                <span class="pick__path">{{ $entry->uri() ?? '—' }}</span>
                            @else
                                <span style="min-width: 0">
                                    <span class="pick__title">{{ $entry->title() ?: 'Untitled' }}@if ($entry->get('translation_of'))<span class="pick__lang" title="Translation">{{ strtoupper((string) $entry->get('lang')) }}</span>@endif@if ($n = $noteCounts[$entry->id()] ?? 0)<span class="pick__notes" title="{{ $n }} sticky {{ str('note')->plural($n) }}">{{ $n }}</span>@endif</span>
                                    <span class="pick__meta">
                                        {{ ucfirst($entry->status()) }}@if ($r = $reviewStates[$entry->id()] ?? null) · {{ $r === 'waiting' ? 'in review' : 'sent back' }}@endif
                                        · {{ $entry->date()?->format('j M Y') ?? ($entry->lastModified() ? \Illuminate\Support\Carbon::parse($entry->lastModified())->format('j M Y') : '') }}
                                    </span>
                                </span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the chosen entry --}}
    <section class="page page--right" style="--ribbon: {{ $model->get('sticker', $structured ? 'var(--mint)' : 'var(--sun)') }}">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($chosen)
            <span style="display: flex; flex-wrap: wrap; gap: 8px; align-self: flex-start">
                <span class="sticker sticker--lg sticker--{{ $chosen->status() }}">{{ ucfirst($chosen->status()) }}</span>
                @if ($r = $reviewStates[$chosen->id()] ?? null)
                    <span class="sticker sticker--lg sticker--{{ $r === 'waiting' ? 'sky' : 'rose' }}" style="--tilt: 2deg">{{ $r === 'waiting' ? 'In review' : 'Sent back' }}</span>
                @endif
            </span>
            <h2 class="display" style="margin: 20px 0 8px; max-width: 600px">{{ $chosen->title() ?: 'Untitled' }}</h2>

            @if ($structured)
                <p style="font-family: var(--mono); font-size: 14px; color: var(--faint); overflow-wrap: anywhere">{{ parse_url(url('/'), PHP_URL_HOST) }}{{ $chosen->uri() ?? '' }}</p>

                <h3 class="field__label" style="margin: 30px 0 12px">Template</h3>
                <div class="templates" role="radiogroup" aria-label="Template">
                    @php $current = (string) $chosen->get('template', 'default'); @endphp
                    @foreach ($templates as $value => [$label, $hint])
                        <button type="button" role="radio" class="template" aria-checked="{{ $current === $value ? 'true' : 'false' }}"
                                wire:click="setTemplate('{{ $value }}')" @disabled(! $canEditChosen)>
                            <span class="template__thumb template__thumb--{{ $value }}" aria-hidden="true"><i></i><i></i><i></i></span>
                            <span class="template__label">{{ $label }}</span>
                            <span class="template__hint">{{ $hint }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="setting-row" style="margin-top: 24px">
                    <span>
                        <span style="display: block; font-size: 15px; font-weight: 500">Show in site menu</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">Appears in the header navigation</span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-checked="{{ $inMenu ? 'true' : 'false' }}" aria-label="Show in site menu"
                            wire:click="toggleMenu" @disabled(! $canArrange)></button>
                </div>

                <dl class="facts">
                    <div>
                        <dt class="eyebrow">Parent</dt>
                        <dd>
                            @if ($canArrange)
                                <label for="page-parent" class="sr-only">Parent page</label>
                                <select id="page-parent" class="input" style="min-height: 36px" wire:change="moveUnder($event.target.value)">
                                    <option value="">— Top level</option>
                                    @foreach ($parentChoices as $choice)
                                        <option value="{{ $choice->id() }}" @selected($parentEntry?->id() === $choice->id())>{{ $choice->title() ?: 'Untitled' }}</option>
                                    @endforeach
                                </select>
                            @else
                                {{ $parentEntry?->title() ?? '—' }}
                            @endif
                        </dd>
                    </div>
                    <div><dt class="eyebrow">Author</dt><dd>{{ $author?->name ?? '—' }}</dd></div>
                    <div><dt class="eyebrow">Edited</dt><dd>{{ $chosen->lastModified() ? \Illuminate\Support\Carbon::parse($chosen->lastModified())->diffForHumans() : '—' }}</dd></div>
                    @if ($chosenReading)
                        <div><dt class="eyebrow">Read</dt><dd>{{ number_format($chosenReading['views']) }} {{ str('view')->plural($chosenReading['views']) }} in 30 days</dd></div>
                    @endif
                </dl>
            @else
                <p class="serif muted" style="font-style: italic; font-size: 17px">
                    {{ $author ? 'by '.$author->name.' · ' : '' }}{{ str_word_count(strip_tags((string) $chosen->get('content', ''))) }} words
                    @if ($chosen->date()) · {{ $chosen->date()->format('j F Y') }} @endif
                </p>

                <div class="cover-preview">
                    @if ($cover)
                        <img src="{{ $cover }}" alt="" style="object-position: {{ \App\Support\Focus::forPath(((array) $chosen->get('cover', []))[0] ?? null) }}">
                    @else
                        <span><x-admin.icon name="image" size="20" /> No cover picture yet</span>
                    @endif
                </div>

                @if ($excerpt)
                    <p class="serif" style="margin-top: 24px; max-width: 620px; font-size: 19px; line-height: 1.65; color: var(--ink-2)">{{ $excerpt }}</p>
                @endif

                <dl class="facts">
                    <div><dt class="eyebrow">Address</dt><dd style="font-family: var(--mono); font-size: 13px; overflow-wrap: anywhere">{{ $chosen->uri() ?? '—' }}</dd></div>
                    <div><dt class="eyebrow">Tags</dt><dd>{{ $tags ? implode(', ', $tags) : '—' }}</dd></div>
                    <div><dt class="eyebrow">Edited</dt><dd>{{ $chosen->lastModified() ? \Illuminate\Support\Carbon::parse($chosen->lastModified())->diffForHumans() : '—' }} · {{ $editions }} {{ str('edition')->plural($editions) }}</dd></div>
                    @if ($chosenReading)
                        <div><dt class="eyebrow">Read</dt><dd>{{ number_format($chosenReading['views']) }} {{ str('view')->plural($chosenReading['views']) }} in 30 days @if ($chosenReading['views'] >= 5) · {{ (int) round(min(1, $chosenReading['reads'] / $chosenReading['views']) * 100) }}% to the end @endif</dd></div>
                    @endif
                </dl>
            @endif

            <div style="margin-top: auto; padding-top: 32px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
                    @if ($canEditChosen)
                        <a href="{{ route('admin.entries.edit', [$model->handle(), $chosen->id()]) }}" class="btn btn--ink" wire:navigate>{{ $structured ? 'Edit page' : 'Edit' }}</a>
                    @endif
                    @if ($structured)
                        <a href="{{ route('admin.entries.create', [$model->handle(), 'parent' => $chosen->id()]) }}" class="btn" wire:navigate>Add sub-page</a>
                        @if ($canArrange)
                            <button type="button" class="icon-btn" wire:click="nudge(-1)" aria-label="Move up"><span aria-hidden="true">↑</span></button>
                            <button type="button" class="icon-btn" wire:click="nudge(1)" aria-label="Move down"><span aria-hidden="true">↓</span></button>
                        @endif
                    @endif
                    <a href="{{ route('preview', $chosen->id()) }}" class="btn" target="_blank" rel="noopener">Preview</a>
                    @if ($chosen->uri() && $chosen->status() === 'published')
                        <a href="{{ url($chosen->uri()) }}" class="btn btn--quiet" target="_blank" rel="noopener">View on site ↗</a>
                    @endif
                </div>
                <x-admin.folio side="right" style="margin: 0; padding: 0" />
            </div>
            @unless ($canEditChosen)
                <p class="field__hint" style="margin-top: 10px">Only its author or an editor can change it.</p>
            @endunless
        @else
            <p class="empty-note" style="max-width: 460px">Pick an entry on the left to see it here.</p>
        @endif
    </section>
</div>
