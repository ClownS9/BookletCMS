<div style="display: contents">
    {{-- A chapter in the Dashboard layout: one table, with status tabs, search,
         sorting, ticks for doing many at once, and a menu on each row. --}}
    @php
        $me = (string) request()->user()?->id;
        $dated = $model->dated();
        $sortMark = fn (string $col) => ltrim($sort, '-') === $col ? (str_starts_with($sort, '-') ? '↓' : '↑') : '↕';
        $ariaSort = fn (string $col) => ltrim($sort, '-') === $col ? (str_starts_with($sort, '-') ? 'descending' : 'ascending') : 'none';
    @endphp
    <section class="page page--single page--dash" aria-labelledby="chapter-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="chapter-title">{{ __($model->title()) }}</h1>
                </div>
                @if ($structured && $canArrange)
                    <a href="{{ route('admin.menus') }}" class="btn" wire:navigate>{{ __('Site menus') }}</a>
                @endif
                @if ($hasTaxonomies)
                    <a href="{{ route('admin.taxonomies') }}" class="btn has-handheld-twin" wire:navigate>{{ __('Categories & tags') }}</a>
                @endif
                @if ($dated)
                    <a href="{{ route('admin.calendar') }}" class="btn has-handheld-twin" wire:navigate>{{ __('Calendar') }}</a>
                @endif
                <a href="{{ route('admin.entries.create', $model->handle()) }}" class="btn btn--ink has-handheld-twin" wire:navigate>
                    <x-admin.icon name="plus" size="16" /> {{ $structured ? __('New page') : __('New') }}
                </a>
            </div>
            <x-admin.insert-links :chapter="$model->title()" />

            <section class="dash-card" aria-label="{{ __('Everything in :what', ['what' => __($model->title())]) }}">
                <div class="dash-tabs has-handheld-twin" role="group" aria-label="{{ __('Filter by status') }}">
                    @foreach ($statuses as $value => $label)
                        <button type="button" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">
                            {{ __($label) }} <b>{{ number_format($value === 'all' ? $total : ($counts[$value] ?? 0)) }}</b>
                        </button>
                    @endforeach
                </div>

                @if ($movingEntry)
                    <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Move a page') }}" wire:key="moving">
                        <label for="move-parent"><strong>{{ __('Put “:name” under', ['name' => $movingEntry->title() ?: __('Untitled')]) }}</strong></label>
                        <select id="move-parent" class="input" style="width: auto; min-width: 220px" wire:change="moveTo($event.target.value)">
                            <option value="" disabled selected>{{ __('Choose a page…') }}</option>
                            <option value="">{{ __('— Top level') }}</option>
                            @foreach ($moveChoices as $choice)
                                <option value="{{ $choice->id() }}">{{ $choice->title() ?: __('Untitled') }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="cancelMove">{{ __('Cancel') }}</button>
                    </div>
                @elseif ($ticked === [])
                    <div class="dash-tools">
                        <div class="search">
                            <x-admin.icon name="search" size="16" />
                            <label for="entry-search" class="sr-only">{{ __('Search :what', ['what' => __($model->title())]) }}</label>
                            <input id="entry-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ $within === 'everything' ? __('Search titles, the writing, tags and writers') : __('Search this chapter') }}">
                        </div>
                        @include('livewire.admin.partials.entry-filters-toggle')
                        @if ($structured)
                            <span class="dash-tools__links">{{ __('Nested the way they appear on the site.') }}</span>
                        @else
                            <span class="dash-tools__links has-handheld-twin">
                                <a href="{{ route('admin.print', ['collection' => $model->handle()]) }}" wire:navigate>{{ __('Print as a book') }}</a>
                            </span>
                        @endif
                    </div>
                    @include('livewire.admin.partials.entry-filters')
                @else
                    <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                        @include('livewire.admin.partials.ticked-count')
                        @if ($canArrange)
                            <button type="button" class="btn btn--sm" wire:click="publishTicked">{{ __('Publish') }}</button>
                            <button type="button" class="btn btn--sm" wire:click="draftTicked">{{ __('Make drafts') }}</button>
                        @endif
                        <button type="button" class="btn btn--danger btn--sm" wire:click="binTicked" wire:confirm="{{ trans_choice('Move :count to the bin? It waits there for 30 days.|Move :count to the bin? They wait there for 30 days.', count($ticked)) }}">{{ __('Move to the bin') }}</button>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="untickAll">{{ __('Clear') }}</button>
                        @if ($termFields !== [])
                            <form wire:submit="termTicked">
                                @if (count($termFields) > 1)
                                    <label for="bulk-field" class="sr-only">{{ __('Add to') }}</label>
                                    <select id="bulk-field" class="input" wire:model.live="bulkField">
                                        @foreach ($termFields as $handle => $f)
                                            <option value="{{ $handle }}">{{ __($f['label']) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <label for="bulk-words" class="sr-only">{{ __($termFields[$bulkField]['label'] ?? 'Tags') }}</label>
                                <input id="bulk-words" class="input" type="text" wire:model="bulkWords" list="bulk-terms" maxlength="80" autocomplete="off" placeholder="{{ __('Add a category or tag…') }}">
                                <datalist id="bulk-terms">
                                    @foreach ($termTitles as $t)
                                        <option value="{{ $t }}"></option>
                                    @endforeach
                                </datalist>
                                <button type="submit" class="btn btn--sm">{{ __('Add') }}</button>
                            </form>
                        @endif
                    </div>
                    @error('bulkWords') <p class="field__error" style="margin: 0 18px 10px">{{ $message }}</p> @enderror
                @endif

                @if ($rows === [])
                    <p class="dash-empty">
                        @if ($search !== '' || $status !== 'all' || $filtersOn > 0)
                            {{ __('Nothing matches. Try another filter.') }}
                            @if ($filtersOn > 0 || $search !== '') <button type="button" class="link-button" wire:click="clearFilters">{{ __('Clear all') }}</button> @endif
                        @else
                            {{ __('Nothing in :what yet.', ['what' => __($model->title())]) }} <a href="{{ route('admin.entries.create', $model->handle()) }}" wire:navigate>{{ __('Write the first one →') }}</a>
                        @endif
                    </p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="dash-table__tick">
                                        <input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked(array_diff($pageIds, $ticked) === [])
                                               aria-label="{{ $lastPage > 1 ? __('Choose all on this page') : __('Choose all') }}">
                                    </th>
                                    @if ($structured)
                                        <th scope="col">{{ __('Title') }}</th>
                                        <th scope="col" class="is-wide">{{ __('Template') }}</th>
                                        <th scope="col" class="is-wide">{{ __('In the menu') }}</th>
                                        <th scope="col" class="is-wide">{{ __('Edited') }}</th>
                                    @else
                                        <th scope="col" aria-sort="{{ $ariaSort('title') }}"><button type="button" class="dash-table__sort" wire:click="sortBy('title')">{{ __('Title') }}<span aria-hidden="true">{{ $sortMark('title') }}</span></button></th>
                                        <th scope="col" class="is-wide">{{ __('Author') }}</th>
                                        @if ($termFields !== [])
                                            <th scope="col" class="is-wide">{{ __('Categories & tags') }}</th>
                                        @endif
                                        @if ($dated)
                                            <th scope="col" aria-sort="{{ $ariaSort('date') }}"><button type="button" class="dash-table__sort" wire:click="sortBy('date')">{{ __('Date') }}<span aria-hidden="true">{{ $sortMark('date') }}</span></button></th>
                                        @endif
                                        <th scope="col" aria-sort="{{ $ariaSort('edited') }}" class="is-wide"><button type="button" class="dash-table__sort" wire:click="sortBy('edited')">{{ __('Edited') }}<span aria-hidden="true">{{ $sortMark('edited') }}</span></button></th>
                                    @endif
                                    <th scope="col">{{ __('Status') }}</th>
                                    <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    @php
                                        $entry = $row['entry'];
                                        $name = $entry->title() ?: __('Untitled');
                                        $mine = $canArrange || (string) $entry->get('author') === $me;
                                        $writer = $authors[$entry->get('author')] ?? null;
                                        $words = collect((array) $entry->get('categories', []))->merge((array) $entry->get('tags', []))->filter(fn ($w) => is_string($w) && $w !== '')->unique()->values();
                                        $review = $reviewStates[$entry->id()] ?? null;
                                        $ticks = in_array($entry->id(), $ticked, true);
                                    @endphp
                                    <tr wire:key="row-{{ $entry->id() }}" @class(['is-ticked' => $ticks])>
                                        <td class="dash-table__tick">
                                            <input type="checkbox" wire:model.live="ticked" value="{{ $entry->id() }}" aria-label="{{ __('Choose “:name”', ['name' => $name]) }}">
                                        </td>
                                        <td class="dash-table__main">
                                            @if ($structured && $row['depth'] > 0)<span class="dash-table__indent" style="--depth: {{ $row['depth'] }}" aria-hidden="true"></span>@endif
                                            <a class="dash-table__title" href="{{ $mine ? route('admin.entries.edit', [$model->handle(), $entry->id()]) : route('preview', $entry->id()) }}" @if ($mine) wire:navigate @else target="_blank" rel="noopener" @endif>{{ $name }}</a>
                                            @if ($entry->get('translation_of'))<span class="pick__lang" title="{{ __('Translation') }}">{{ strtoupper((string) $entry->get('lang')) }}</span>@endif
                                            @if ($n = $noteCounts[$entry->id()] ?? 0)<span class="pick__notes" title="{{ trans_choice(':count sticky note|:count sticky notes', $n) }}">{{ $n }}</span>@endif
                                            <span class="dash-table__sub">{{ $entry->uri() ?? '—' }}</span>
                                        </td>
                                        @if ($structured)
                                            @php $template = (string) $entry->get('template', 'default'); @endphp
                                            <td class="is-wide">
                                                @if ($mine)
                                                    <label for="tpl-{{ $entry->id() }}" class="sr-only">{{ __('Template for “:name”', ['name' => $name]) }}</label>
                                                    <select id="tpl-{{ $entry->id() }}" class="input dash-table__select" wire:change="templateFor('{{ $entry->id() }}', $event.target.value)">
                                                        @foreach ($templates as $value => [$label, $hint])
                                                            <option value="{{ $value }}" @selected($template === $value)>{{ __($label) }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    {{ __($templates[$template][0] ?? 'Default') }}
                                                @endif
                                            </td>
                                            <td class="is-wide">
                                                <button type="button" class="switch switch--table" role="switch" aria-checked="{{ in_array($entry->id(), $menuIds, true) ? 'true' : 'false' }}"
                                                        aria-label="{{ __('Show “:name” in the site menu', ['name' => $name]) }}" wire:click="menuFor('{{ $entry->id() }}')" @disabled(! $canArrange)></button>
                                            </td>
                                        @else
                                            <td class="is-wide">
                                                @if ($writer)
                                                    <span class="dash-person"><x-admin.avatar :user="$writer" size="24" />{{ $writer->name }}</span>
                                                @else
                                                    <span class="is-quiet">—</span>
                                                @endif
                                            </td>
                                            @if ($termFields !== [])
                                                <td class="is-wide">
                                                @forelse ($words->take(2) as $w)
                                                    <span class="dash-tag">{{ $w }}</span>
                                                @empty
                                                    <span class="is-quiet">—</span>
                                                @endforelse
                                                @if ($words->count() > 2)<span class="is-quiet">+{{ $words->count() - 2 }}</span>@endif
                                            </td>
                                        @endif
                                        @if ($dated)
                                            <td class="is-quiet">{{ $entry->date()?->translatedFormat('j M Y') ?? '—' }}</td>
                                        @endif
                                        @endif
                                        <td class="is-quiet is-wide">{{ $entry->lastModified() ? \Illuminate\Support\Carbon::parse($entry->lastModified())->diffForHumans() : '—' }}</td>
                                        <td>
                                            @if ($review === 'waiting')
                                                <span class="dash-status dash-status--review"><i aria-hidden="true"></i>{{ __('In review') }}</span>
                                            @else
                                                <span class="dash-status dash-status--{{ $entry->status() }}"><i aria-hidden="true"></i>{{ __(ucfirst($entry->status())) }}@if ($structured && $entry->status() === 'scheduled' && $entry->date()) · {{ $entry->date()->translatedFormat('j M, g:i A') }}@endif@if ($review && $review !== 'waiting') · {{ __('sent back') }}@endif</span>
                                            @endif
                                        </td>
                                        <td style="width: 1%">
                                            <div class="dash-more" x-data="bookletRowMenu()" x-on:keydown.escape="close()" x-on:click.outside="close()">
                                                <button type="button" class="icon-btn" x-ref="button" x-on:click="toggle()" :aria-expanded="open.toString()" aria-expanded="false" aria-label="{{ __('More for “:name”', ['name' => $name]) }}">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                                                </button>
                                                <div class="dash-more__menu" x-ref="menu" x-show="open" x-cloak :style="place" x-transition.opacity.duration.120ms>
                                                    @if ($mine)
                                                        <a href="{{ route('admin.entries.edit', [$model->handle(), $entry->id()]) }}" wire:navigate>{{ __('Edit') }}</a>
                                                    @endif
                                                    @if ($structured)
                                                        <a href="{{ route('admin.entries.create', [$model->handle(), 'parent' => $entry->id()]) }}" wire:navigate>{{ __('Add sub-page') }}</a>
                                                        @if ($canArrange)
                                                            <button type="button" x-on:click="open = false" wire:click="nudgeRow('{{ $entry->id() }}', -1)">{{ __('Move up') }}</button>
                                                            <button type="button" x-on:click="open = false" wire:click="nudgeRow('{{ $entry->id() }}', 1)">{{ __('Move down') }}</button>
                                                            <button type="button" x-on:click="open = false" wire:click="startMove('{{ $entry->id() }}')">{{ __('Put under another page…') }}</button>
                                                        @endif
                                                    @endif
                                                    <a href="{{ route('preview', $entry->id()) }}" target="_blank" rel="noopener">{{ __('Preview') }}</a>
                                                    @if ($entry->uri() && $entry->status() === 'published')
                                                        <a href="{{ url($entry->uri()) }}" target="_blank" rel="noopener">{{ __('View on site ↗') }}</a>
                                                    @endif
                                                    @if ($mine)
                                                        <button type="button" class="is-danger" x-on:click="open = false" wire:click="binOne('{{ $entry->id() }}')" wire:confirm="{{ __('Move “:name” to the bin? It waits there for 30 days.', ['name' => $name]) }}">{{ __('Move to the bin') }}</button>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($structured)
                        <div class="dash-foot"><span>{{ trans_choice(':count page|:count pages', $shown) }}</span></div>
                    @else
                        <x-admin.pager :of="$pages" :sizes="$pageSizes" class="dash-foot" id="entries-pager" always />
                    @endif
                @endif
            </section>
        </div>
    </section>
</div>
