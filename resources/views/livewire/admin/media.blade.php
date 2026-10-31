@php
    $chosen = $this->chosen;
    $tilts = ['-2deg', '1.5deg', '-1deg', '2deg', '1deg', '-1.5deg', '2.5deg', '-2.5deg'];
    $tapes = ['-3deg', '4deg'];
@endphp

<div style="display: contents">
    {{-- Left page: upload, folders, the chosen file --}}
    <section class="page page--left page--stack" style="flex-basis: 340px">
        <div>
            <div class="page-head">
                <x-admin.crumbs />
                <p class="eyebrow">{{ \App\Support\AdminNav::chapterLabel() }} · {{ trans_choice(':count file|:count files', $total) }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Media') }}</h1>
            </div>
            <x-admin.insert-links chapter="Media" />
            @if (request()->user()->isAdmin())
                <div class="chapter-links">
                    <button type="button" class="back link-button" wire:click="{{ $sizesOpen ? 'closeSizes' : 'openSizes' }}" aria-expanded="{{ $sizesOpen ? 'true' : 'false' }}">
                        <x-admin.icon name="image" size="16" /> {{ __('Picture sizes') }} {{ $sizesOpen ? '×' : '→' }}
                    </button>
                </div>
            @endif
        </div>

        <x-admin.card>
            @if ($sizesOpen)
                @include('livewire.admin.media-sizes')
            @endif

            @if (! $this->containerModel)
                <p class="empty-note">{!! __('There’s no Media container yet. Run the starter seeder, or add one in :folder.', ['folder' => '<code>content/assets</code>']) !!}</p>
            @else
                @if ($containers->count() > 1)
                    <div class="field">
                        <label for="container">{{ __('Album') }}</label>
                        <select id="container" wire:model.live="container">
                            @foreach ($containers as $c)
                                <option value="{{ $c->handle() }}">{{ $c->title() }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if (! $canChange)
                    <p class="field__hint">{{ __('Your role can choose pictures that are already here, but not add or change them.') }}</p>
                @else
                <label class="dropzone" x-data="{ over: false }"
                       x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false"
                       x-on:drop="over = false"
                       :class="{ 'dropzone--over': over }">
                    <input type="file" multiple wire:model="uploads" class="dropzone__input">
                    <x-admin.icon name="upload" size="20" />
                    <span wire:loading.remove wire:target="uploads">{{ __('Drop files or browse') }} <span class="muted">· {{ __('into :folder', ['folder' => $folder === '' ? __('the top folder') : $folder]) }}</span></span>
                    <span wire:loading wire:target="uploads">{{ __('Taping in…') }}</span>
                </label>
                @error('uploads') <p class="field__error">{{ $message }}</p> @enderror
                @error('uploads.*') <p class="field__error">{{ $message }}</p> @enderror
                @endif

                <nav aria-label="{{ __('Folders') }}">
                    <h2 class="eyebrow section-label">{{ __('Folders') }}</h2>
                    <div class="picks">
                        <button type="button" class="pick pick--row" wire:click="$set('folder', '')" aria-pressed="{{ $folder === '' ? 'true' : 'false' }}">
                            <span style="flex: 1 1 auto">{{ __('All files') }}</span><span class="toc__count" style="font-size: 12px">{{ $total }}</span>
                        </button>
                        <button type="button" class="pick pick--row" wire:click="$set('folder', '/')" aria-pressed="{{ $folder === '/' ? 'true' : 'false' }}">
                            <span style="flex: 1 1 auto">{{ __('Top folder') }}</span><span class="toc__count" style="font-size: 12px">{{ $folderCounts['/'] ?? 0 }}</span>
                        </button>
                        @foreach ($this->folders as $f)
                            <button type="button" class="pick pick--row" wire:key="folder-{{ $f }}" wire:click="$set('folder', '{{ $f }}')" aria-pressed="{{ $folder === $f ? 'true' : 'false' }}">
                                <span style="flex: 1 1 auto">{{ str($f)->headline() }}</span><span class="toc__count" style="font-size: 12px">{{ $folderCounts[$f] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                    @if ($canChange)
                    <form wire:submit="createFolder" style="display: flex; gap: 8px; margin-top: 10px">
                        <label for="new-folder" class="sr-only">{{ __('New folder') }}</label>
                        <input id="new-folder" class="input" type="text" wire:model="newFolder" placeholder="{{ __('New folder') }}">
                        <button type="submit" class="btn" style="flex: none">{{ __('Add') }}</button>
                    </form>
                    @error('newFolder') <p class="field__error">{{ $message }}</p> @enderror
                    @endif
                </nav>

                @if ($chosen)
                    <div style="padding-top: 20px; border-top: 1px solid var(--rule); display: flex; flex-direction: column; gap: 12px">
                        <h2 class="eyebrow section-label">{{ __('Selected') }}</h2>
                        <p style="font-family: var(--mono); font-size: 14px; overflow-wrap: anywhere">{{ $chosen->path() }}</p>
                        <p class="muted" style="font-size: 13px">
                            {{ \App\Support\Sizes::file((int) $chosen->get('size', 0)) }}
                            @if ($chosen->get('width')) · {{ $chosen->get('width') }}×{{ $chosen->get('height') }} @endif
                            · {{ strtoupper($chosen->attribute('extension')) }}
                        </p>

                        @if ($chosen->isImage() && ($urls[$chosen->path()] ?? null) && $canChange)
                            @php [$fx, $fy] = \App\Support\Focus::parse($chosen->get('focus')); @endphp
                            {{-- The focal point: click or drag to the spot that matters --}}
                            <div class="focus-pick" wire:key="focus-{{ md5($chosen->path().$chosen->get('focus')) }}"
                                 x-data="bookletFocus({{ $fx }}, {{ $fy }})">
                                <p class="field__label" id="focus-label">{{ __('Focal point') }}</p>
                                <p class="field__hint" style="margin-top: -2px">{{ __('Click the part that matters (a face, the cake). It stays in view however the picture is cropped.') }}</p>
                                <div class="focus-pick__stage" x-ref="stage"
                                     x-on:pointerdown.prevent="grab($event)" x-on:pointermove="drag($event)" x-on:pointerup="drop($event)" x-on:pointercancel="drop($event)"
                                     x-on:keydown="nudge($event)" tabindex="0" role="slider" aria-labelledby="focus-label"
                                     :aria-valuetext="`${x}% across, ${y}% down`">
                                    <img src="{{ $urls[$chosen->path()] }}" alt="" draggable="false">
                                    <span class="focus-pick__mark" :style="`left: ${x}%; top: ${y}%`" aria-hidden="true"></span>
                                </div>
                                {{-- Every named size, like prints laid on the table: they follow the dot as it moves --}}
                                <div class="size-prints" aria-hidden="true">
                                    @foreach ($pictureSizes as $handle => $size)
                                        <span class="size-print" style="--tilt: {{ ['-2deg', '1.5deg', '-1deg', '2deg', '-1.5deg', '1deg'][$loop->index % 6] }}; --w: {{ $size['width'] }}; --h: {{ $size['height'] }}">
                                            <img src="{{ $urls[$chosen->path()] }}" alt="" :style="`object-position: ${x}% ${y}%`">
                                            <small>{{ __($size['label']) }} · {{ \App\Support\Crops::ratio($size['width'], $size['height']) }}</small>
                                        </span>
                                    @endforeach
                                </div>
                                @if ($chosen->get('focus'))
                                    <button type="button" class="link-button" style="align-self: flex-start; font-size: 13px" wire:click="clearFocus">{{ __('Back to the middle') }}</button>
                                @endif
                            </div>
                        @endif

                        {{-- Links to the picture in each named size (for pasting into a post or another site) --}}
                        @if ($chosen->isImage() && \App\Support\Pictures::resizable($chosen->path(), $container ?: 'media'))
                            <div class="size-links">
                                <span class="field__label">{{ __('Copy a size') }}</span>
                                <div class="size-links__row">
                                    @foreach ($pictureSizes as $handle => $size)
                                        @php $cropUrl = url(\App\Support\Crops::url($chosen->path(), $handle, $container ?: 'media')); @endphp
                                        <button type="button" class="size-link" x-data="{ copied: false }"
                                                x-on:click="navigator.clipboard.writeText(@js($cropUrl)); copied = true; setTimeout(() => copied = false, 1500)"
                                                title="{{ $size['width'] }}×{{ $size['height'] }}, cut around the focal point">
                                            <span x-show="! copied">{{ __($size['label']) }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <form wire:submit="saveAlt" class="field">
                            <label for="alt-text">{{ __('Alt text') }}</label>
                            <input id="alt-text" type="text" wire:model="alt" placeholder="{{ __('Describe the image for screen readers') }}" @readonly(! $canChange)>
                            @error('alt') <p class="field__error">{{ $message }}</p> @enderror
                            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px">
                                @if ($canChange) <button type="submit" class="btn btn--ink">{{ __('Save') }}</button> @endif
                                @if ($urls[$chosen->path()] ?? null)
                                    <button type="button" class="btn"
                                            x-data="{ copied: false }"
                                            x-on:click="navigator.clipboard.writeText(@js(url($urls[$chosen->path()]))); copied = true; setTimeout(() => copied = false, 1500)">
                                        <span x-show="! copied">{{ __('Copy link') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                    </button>
                                @endif
                                @if ($canChange)
                                <button type="button" class="btn btn--danger" wire:click="deleteChosen"
                                        wire:confirm="{{ __('Move :name to the bin? Posts using it will lose the picture until it’s put back.', ['name' => $chosen->attribute('basename')]) }}">{{ __('Delete') }}</button>
                                @endif
                            </div>
                        </form>
                    </div>
                @endif
            @endif
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the album --}}
    <section class="page page--right" style="--ribbon: var(--sky)">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            <div class="ribbon-clear" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding-bottom: 20px">
                <div class="search" style="flex: 0 1 360px">
                    <x-admin.icon name="search" size="16" />
                    <label for="media-search" class="sr-only">{{ __('Search media') }}</label>
                    <input id="media-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('Search by name or alt text') }}">
                </div>
                @if ($missingAlt > 0 || $noAlt)
                    <button type="button" class="pill" wire:click="$toggle('noAlt')" aria-pressed="{{ $noAlt ? 'true' : 'false' }}" title="{{ __('Pictures without a description for people who can’t see them') }}">{{ __('No alt text') }} <b>{{ $missingAlt }}</b></button>
                @endif
                <div class="view-switch" role="group" aria-label="{{ __('Show as') }}">
                    <button type="button" wire:click="setView('grid')" aria-pressed="{{ $view === 'grid' ? 'true' : 'false' }}" title="{{ __('Grid') }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><span class="sr-only">{{ __('Grid') }}</span></button>
                    <button type="button" wire:click="setView('list')" aria-pressed="{{ $view === 'list' ? 'true' : 'false' }}" title="{{ __('List') }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg><span class="sr-only">{{ __('List') }}</span></button>
                </div>
                @if ($this->containerModel && $canChange)
                    <button type="button" class="btn btn--quiet" wire:click="sync" style="font-size: 14px">{{ __('Find new files') }}</button>
                @endif
            </div>

            @if ($visible->isEmpty())
                <div style="flex: 1 1 auto; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 40px 0">
                    <div aria-hidden="true" class="empty-album">
                        <span></span><span></span><i></i>
                    </div>
                    <h2 class="heading" style="margin-top: 26px">
                        {{ $search !== '' ? __('Nothing by that name') : __('No pictures in this album yet') }}
                    </h2>
                    <p class="lede" style="margin-top: 8px; max-width: 340px; font-size: 15px">
                        {{ __('Drop images, PDFs or audio on the left. They’ll be ready to tape into any post or page.') }}
                    </p>
                </div>
            @elseif ($view === 'list')
                @php
                    $sortMark = fn (string $col) => ltrim($sort, '-') === $col ? (str_starts_with($sort, '-') ? '↓' : '↑') : '↕';
                    $ariaSort = fn (string $col) => ltrim($sort, '-') === $col ? (str_starts_with($sort, '-') ? 'descending' : 'ascending') : 'none';
                @endphp
                <div class="dash-table-wrap media-list">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th scope="col" aria-sort="{{ $ariaSort('name') }}"><button type="button" class="dash-table__sort" wire:click="sortBy('name')">{{ __('File') }}<span aria-hidden="true">{{ $sortMark('name') }}</span></button></th>
                                <th scope="col" class="is-wide">{{ __('Folder') }}</th>
                                <th scope="col" class="is-num" aria-sort="{{ $ariaSort('size') }}"><button type="button" class="dash-table__sort" wire:click="sortBy('size')">{{ __('Size') }}<span aria-hidden="true">{{ $sortMark('size') }}</span></button></th>
                                <th scope="col" class="is-wide">{{ __('Dimensions') }}</th>
                                <th scope="col" class="is-wide">{{ __('Alt text') }}</th>
                                <th scope="col" class="is-wide" aria-sort="{{ $ariaSort('date') }}"><button type="button" class="dash-table__sort" wire:click="sortBy('date')">{{ __('Added') }}<span aria-hidden="true">{{ $sortMark('date') }}</span></button></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($visible as $asset)
                                @php $alt = trim((string) $asset->get('alt', '')); $bytes = (int) $asset->get('size', 0); @endphp
                                <tr wire:key="row-{{ md5($asset->path()) }}" @class(['is-open' => $chosen?->path() === $asset->path()])>
                                    <td class="dash-table__main">
                                        <button type="button" class="dash-table__open media-list__file" wire:click="pick(@js($asset->path()))">
                                            <span class="media-list__thumb" aria-hidden="true">
                                                @if ($asset->isImage() && ($urls[$asset->path()] ?? null))<img src="{{ $urls[$asset->path()] }}" alt="" loading="lazy" decoding="async">@else<span>{{ strtoupper($asset->attribute('extension')) }}</span>@endif
                                            </span>
                                            <span style="min-width: 0"><span class="dash-table__name">{{ $asset->attribute('basename') }}</span><span class="dash-table__sub dash-table__sub--plain">{{ strtoupper((string) $asset->attribute('extension')) }}</span></span>
                                        </button>
                                    </td>
                                    <td class="is-wide is-quiet">{{ $asset->attribute('folder') && $asset->attribute('folder') !== '/' ? $asset->attribute('folder') : '—' }}</td>
                                    <td class="is-num"><span @class(['is-big' => $bytes > 2 * 1048576])>{{ $bytes ? \App\Support\Sizes::file($bytes) : '—' }}</span></td>
                                    <td class="is-wide is-quiet">{{ $asset->get('width') ? $asset->get('width').'×'.$asset->get('height') : '—' }}</td>
                                    <td class="is-wide media-list__alt">
                                        @if (! $asset->isImage())<span class="is-quiet">—</span>
                                        @elseif ($alt === '')<span class="dash-tag dash-tag--warn">{{ __('Missing') }}</span>
                                        @else<span title="{{ $alt }}">{{ \Illuminate\Support\Str::limit($alt, 48) }}</span>@endif
                                    </td>
                                    <td class="is-wide is-quiet">{{ $asset->lastModified() ? \Illuminate\Support\Carbon::parse($asset->lastModified())->translatedFormat('j M Y') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-admin.pager :of="$visible" :sizes="$pageSizes" class="picks-pager" id="media-pager" />
            @else
                <div class="album">
                    @foreach ($visible as $i => $asset)
                        <button type="button" class="photo" wire:key="asset-{{ md5($asset->path()) }}" wire:click="pick(@js($asset->path()))"
                                aria-pressed="{{ $chosen?->path() === $asset->path() ? 'true' : 'false' }}"
                                aria-label="{{ $asset->attribute('basename') }}"
                                style="--tilt: {{ $tilts[$i % count($tilts)] }}; --tape: {{ $tapes[$i % 2] }}">
                            <span class="photo__frame">
                                @if ($asset->isImage() && ($urls[$asset->path()] ?? null))
                                    <img src="{{ $urls[$asset->path()] }}" alt="{{ $asset->get('alt', '') }}" loading="lazy" decoding="async" @if ($srcset = \App\Support\Pictures::srcset($asset->path(), $asset->container())) srcset="{{ $srcset }}" sizes="220px" @endif style="object-position: {{ \App\Support\Focus::of($asset) }}">
                                @else
                                    <span class="photo__type">{{ strtoupper($asset->attribute('extension')) }}</span>
                                @endif
                            </span>
                            <span class="photo__name">{{ $asset->attribute('basename') }}</span>
                        </button>
                    @endforeach
                </div>
                <x-admin.pager :of="$visible" :sizes="$pageSizes" class="picks-pager" id="media-pager" />
            @endif
        </x-admin.card>

        <x-admin.folio side="right" />
    </section>
</div>
