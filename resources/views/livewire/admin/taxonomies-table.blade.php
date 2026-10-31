@php
    $pageIds = $shown->map(fn ($t) => $t->slug())->all();
    $isTags = $taxonomy === 'tags';
@endphp
<div style="display: contents">
    {{-- Categories & tags in the Dashboard layout: one table for each, with how
         many posts use each one, ticks to remove many, and a drawer to rename,
         merge or remove one. --}}
    <section class="page page--single page--dash" aria-labelledby="terms-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="terms-title">{{ __('Categories & tags') }}</h1>
                    <p>{{ __('Categories are the sections of your blog; each post sits in one. Tags are the little words that link posts about the same thing.') }}</p>
                </div>
            </div>

            <section class="dash-card" aria-label="{{ __('Categories & tags') }}">
                <div class="dash-tabs" role="group" aria-label="{{ __('Show') }}">
                    <button type="button" wire:click="showTaxonomy('categories')" aria-pressed="{{ ! $isTags ? 'true' : 'false' }}">{{ __('Categories') }} <b>{{ $categories->count() }}</b></button>
                    <button type="button" wire:click="showTaxonomy('tags')" aria-pressed="{{ $isTags ? 'true' : 'false' }}">{{ __('Tags') }} <b>{{ $tags->count() }}</b></button>
                </div>

                @if ($ticked === [])
                    <div class="dash-tools">
                        <div class="search">
                            <x-admin.icon name="search" size="16" />
                            <label for="term-search" class="sr-only">{{ __('Find one') }}</label>
                            <input id="term-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ $isTags ? __('Find a tag') : __('Find a category') }}">
                        </div>
                        @if ($canManage)
                            <form wire:submit="{{ $isTags ? 'addTag' : 'addCategory' }}" class="dash-tools__add">
                                <label for="new-term" class="sr-only">{{ $isTags ? __('New tag') : __('New category') }}</label>
                                <input id="new-term" type="text" class="input" wire:model="{{ $isTags ? 'newTag' : 'newCategory' }}" placeholder="{{ $isTags ? __('New tag') : __('New category') }}" autocomplete="off" maxlength="80">
                                <button type="submit" class="btn btn--ink">{{ __('Add') }}</button>
                            </form>
                        @endif
                    </div>
                    @error($isTags ? 'newTag' : 'newCategory') <p class="field__error" style="margin: -6px 18px 10px">{{ $message }}</p> @enderror
                @else
                    <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                        <strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
                        <button type="button" class="btn btn--danger btn--sm" wire:click="removeTicked" wire:confirm="{{ trans_choice('Remove :count from every entry and delete it?|Remove :count from every entry and delete them?', count($ticked)) }}">{{ __('Remove') }}</button>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('ticked', [])">{{ __('Clear') }}</button>
                    </div>
                @endif

                @if ($shown->isEmpty())
                    <p class="dash-empty">{{ $search !== '' ? __('Nothing matches.') : ($isTags ? __('No tags yet. Add one above, or on a post as you write it.') : __('No categories yet.')) }}</p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    @if ($canManage)<th scope="col" class="dash-table__tick"><input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked($pageIds !== [] && array_diff($pageIds, $ticked) === []) aria-label="{{ __('Choose all') }}"></th>@endif
                                    <th scope="col">{{ __('Name') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Address') }}</th>
                                    <th scope="col" class="is-num">{{ __('Entries') }}</th>
                                    <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shown as $i => $term)
                                    @php $n = $count($term); @endphp
                                    <tr wire:key="term-{{ $term->slug() }}" @class(['is-ticked' => in_array($term->slug(), $ticked, true), 'is-open' => $drawer && $chosen?->slug() === $term->slug()])>
                                        @if ($canManage)<td class="dash-table__tick"><input type="checkbox" wire:model.live="ticked" value="{{ $term->slug() }}" aria-label="{{ __('Choose “:name”', ['name' => $term->title()]) }}"></td>@endif
                                        <td class="dash-table__main">
                                            <button type="button" class="dash-table__open" wire:click="choose('{{ $taxonomy }}', '{{ $term->slug() }}')">
                                                <span class="dash-role" style="--tone: var(--{{ ['coral', 'sun', 'mint', 'sky', 'lilac', 'rose', 'sand'][$i % 7] }})">{{ $isTags ? '#' : '' }}{{ $term->title() }}</span>
                                            </button>
                                        </td>
                                        <td class="is-wide">@if ($term->attribute('uri'))<a href="{{ url($term->attribute('uri')) }}" target="_blank" rel="noopener" class="dash-table__sub">{{ $term->attribute('uri') }} ↗</a>@else<span class="is-quiet">—</span>@endif</td>
                                        <td class="is-num">
                                            @if ($n > 0)<a href="{{ route('admin.entries', ['posts', 'in' => [$taxonomy => $term->slug()]]) }}" wire:navigate title="{{ __('See them') }}">{{ number_format($n) }}</a>@else 0 @endif
                                        </td>
                                        <td class="dash-table__actions">
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="choose('{{ $taxonomy }}', '{{ $term->slug() }}')">{{ $canManage ? __('Rename or merge') : __('Open') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="dash-foot"><span>{{ __('Showing :shown of :total', ['shown' => $shown->count(), 'total' => $isTags ? $tags->count() : $categories->count()]) }}</span></div>
                @endif
            </section>
        </div>
    </section>

    @if ($drawer && $chosen)
        <x-admin.drawer :title="($chosen->taxonomy() === 'tags' ? '#' : '').$chosen->title()">
            <p class="drawer__meta">
                {{ $chosen->taxonomy() === 'tags' ? __('Tag') : __('Category') }}
                · {{ trans_choice('used on :count entry|used on :count entries', $count($chosen)) }}
                @if ($chosen->attribute('uri')) · <a href="{{ url($chosen->attribute('uri')) }}" target="_blank" rel="noopener">{{ __('see its page ↗') }}</a> @endif
            </p>
            @if ($canManage)
                <form wire:submit="renameTerm" class="drawer__form">
                    <div class="field">
                        <label for="rename">{{ __('Rename to') }}</label>
                        <input id="rename" type="text" class="input" wire:model="rename" placeholder="{{ $chosen->title() }}" autocomplete="off">
                        @error('rename') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn--ink" style="align-self: flex-start">{{ __('Rename') }}</button>
                </form>
                @if ($mergeChoices->isNotEmpty())
                    <form wire:submit="mergeTerm" class="drawer__form">
                        <div class="field">
                            <label for="merge-into">{{ __('Merge into…') }}</label>
                            <select id="merge-into" class="input" wire:model="mergeInto">
                                <option value="">{{ __('Choose one') }}</option>
                                @foreach ($mergeChoices as $other)
                                    <option value="{{ $other->slug() }}">{{ $other->title() }}</option>
                                @endforeach
                            </select>
                            @error('mergeInto') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn" style="align-self: flex-start">{{ __('Merge') }}</button>
                    </form>
                @endif
                <div class="drawer__actions">
                    @if ($count($chosen) > 0)<a href="{{ route('admin.entries', ['posts', 'in' => [$chosen->taxonomy() => $chosen->slug()]]) }}" class="btn" wire:navigate>{{ __('See its entries') }}</a>@endif
                    <button type="button" class="btn btn--danger" wire:click="removeTerm" wire:confirm="{{ __('Remove “:name” from every entry and delete it?', ['name' => $chosen->title()]) }}">{{ __('Remove') }}</button>
                </div>
            @else
                <p class="field__hint">{{ __('Editors and admins can rename, merge or remove these.') }}</p>
            @endif
        </x-admin.drawer>
    @endif
</div>
