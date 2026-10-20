<div style="display: contents">
    {{-- Left page: which menu, and what to add --}}
    <section class="page page--left page--stack" style="flex-basis: 400px">
        <div>
            <a href="{{ route('admin.entries', 'pages') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Pages</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">Site menus</h1>
            <p style="margin-top: 8px; font-size: 14px; color: var(--faint)">Old links that forward to new pages live in <a href="{{ route('admin.redirects') }}" wire:navigate>Redirects</a>.</p>
        </div>

        <div class="filters" role="group" aria-label="Which menu">
            @foreach ($menus as $handle => $title)
                <button type="button" class="pill" wire:click="switchTo('{{ $handle }}')" aria-pressed="{{ $menu === $handle ? 'true' : 'false' }}"
                        @if ($dirty && $menu !== $handle) wire:confirm="Leave without saving this menu?" @endif>{{ $title }}</button>
            @endforeach
        </div>

        <fieldset style="margin: 0; padding: 0; border: 0">
            <legend class="field__label" style="padding: 0; margin-bottom: 10px">Add a page</legend>
            <div class="mini-list">
                @if (! $hasBlog)
                    <button type="button" class="mini-add" wire:click="addBlog"><span aria-hidden="true">+</span> Blog</button>
                @endif
                @forelse ($addable as $page)
                    <button type="button" class="mini-add" wire:key="add-{{ $page->id() }}" wire:click="addEntry('{{ $page->id() }}')">
                        <span aria-hidden="true">+</span> {{ $page->title() ?: 'Untitled' }}
                        @if ($page->status() !== 'published') <em class="muted" style="font-style: normal; font-size: 12px">· draft</em> @endif
                    </button>
                @empty
                    @if ($hasBlog) <p class="field__hint">Every page is already in this menu.</p> @endif
                @endforelse
            </div>
        </fieldset>

        <form wire:submit="addLink" style="display: flex; flex-direction: column; gap: 10px; padding-top: 18px; border-top: 1px solid var(--rule)">
            <p class="field__label">Or a custom link</p>
            <div class="field">
                <label for="custom-label" class="sr-only">Label</label>
                <input id="custom-label" type="text" wire:model="linkLabel" placeholder="Label, e.g. Shop">
                @error('linkLabel') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="custom-link" class="sr-only">Link</label>
                <input id="custom-link" type="text" wire:model="linkUrl" placeholder="https://" style="font-family: var(--mono); font-size: 13px">
                @error('linkUrl') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn" style="align-self: flex-start">Add link</button>
        </form>

        <div class="menu-help">
            <p class="field__label">How it works</p>
            <ul>
                <li>Rows at the left edge are the menu bar. Rows tucked in under one <b>drop down</b> from it, up to three levels.</li>
                <li><b>Edit</b> a page to let its sub-pages fill in by themselves, or a top row to open as a <b>wide panel</b> with a picture and a few words.</li>
                <li>Every change shows in the preview at once. Only <b>Save</b> puts it on the site.</li>
            </ul>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the menu as one list, then the live preview --}}
    <section class="page page--right" style="--ribbon: var(--mint)"
             x-data="{
                from: null, fromEnd: null, before: null, depth: 0, line: null,
                start(e, i, end) { this.from = i; this.fromEnd = end; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(i)); },
                end() { this.from = this.before = this.line = null; },
                over(e) {
                    if (this.from === null) return;
                    const el = e.currentTarget, i = +el.dataset.i, d = +el.dataset.d, end = +el.dataset.end;
                    if (i >= this.from && i < this.fromEnd) { this.before = this.line = null; return; }   // not inside itself
                    const r = el.getBoundingClientRect(), list = this.$refs.list.getBoundingClientRect();
                    const lower = e.clientY > r.top + r.height / 2;
                    const inward = e.clientX - r.left > 120 + d * 28;
                    if (! lower) { this.before = i; this.depth = d; }
                    else if ((end > i + 1 || inward) && d < {{ $maxDepth }}) { this.before = i + 1; this.depth = d + 1; }
                    else { this.before = end; this.depth = d; }
                    this.line = { top: (lower ? r.bottom : r.top) - list.top, left: this.depth * 28 };
                },
                drop() { if (this.from === null || this.before === null) return this.end(); const [f, b, d] = [this.from, this.before, this.depth]; this.end(); $wire.drop(f, b, d); },
                key(e, i, key) {
                    const act = { ArrowUp: 'up', ArrowDown: 'down', ArrowRight: 'nest', ArrowLeft: 'lift' }[e.key];
                    if (! act) return;
                    e.preventDefault();
                    $wire[act](i).then(() => this.$nextTick(() => document.querySelector('[data-grip=' + CSS.escape(key) + ']')?.focus()));
                },
             }"
             x-on:keydown.window="if (($event.metaKey || $event.ctrlKey) && $event.key === 'z' && ! $event.shiftKey && ! $event.target.closest('input, textarea, [contenteditable]')) { $event.preventDefault(); $wire.undo() }">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear nav-list__head">
            <div>
                <p class="eyebrow">{{ $menus[$menu] ?? $menu }}</p>
                <h2 class="heading heading--sub" style="margin-top: 4px">Arrange</h2>
            </div>
            <button type="button" class="btn btn--quiet" wire:click="undo" @disabled($history === []) aria-keyshortcuts="Control+Z Meta+Z">↶ Undo</button>
        </div>

        @if ($items === [])
            <p class="empty-note">This menu is empty. Add a page on the left.</p>
        @else
            <p id="grip-keys" class="field__hint ribbon-clear" style="margin: 8px 0 0">Drag a row by its grip; further right tucks it under the row above. Or focus a grip and use the arrow keys.</p>
            <ol class="nav-list" x-ref="list" :class="{ 'nav-list--dragging': from !== null }" x-on:dragover.prevent x-on:drop.prevent="drop()">
                @php $top = -1; @endphp
                @foreach ($items as $row)
                    @php if ($row['depth'] === 0) { $top++; } $isOpen = $open === $row['key']; @endphp
                    <li @class(['nav-row', 'nav-row--top' => $row['depth'] === 0, 'nav-row--deep' => $row['depth'] === 2, 'nav-row--hidden' => $row['hidden'], 'nav-row--open' => $isOpen])
                        wire:key="row-{{ $menu }}-{{ $row['key'] }}"
                        style="--depth: {{ $row['depth'] }}; --tone: {{ $tones[$top % count($tones)] }}"
                        data-i="{{ $row['index'] }}" data-d="{{ $row['depth'] }}" data-end="{{ $row['end'] }}"
                        :class="{ 'nav-row--dragging': from !== null && {{ $row['index'] }} >= from && {{ $row['index'] }} < fromEnd }"
                        x-on:dragover.prevent="over($event)">
                        <div class="nav-row__line">
                            <button type="button" class="nav-row__grip" draggable="true" data-grip="{{ $row['key'] }}"
                                    x-on:dragstart="start($event, {{ $row['index'] }}, {{ $row['end'] }})" x-on:dragend="end()"
                                    x-on:keydown="key($event, {{ $row['index'] }}, '{{ $row['key'] }}')"
                                    aria-label="Move {{ $row['label'] }}" aria-describedby="grip-keys">⋮⋮</button>
                            <span class="nav-row__tab" aria-hidden="true"></span>
                            <span class="nav-row__name">
                                <span class="nav-row__label">{{ $row['label'] }}</span>
                                <span class="nav-row__path">{{ $row['detail'] }}</span>
                            </span>
                            <span class="nav-row__badges">
                                @if ($row['follow']) <span class="nav-badge" title="Its sub-pages fill in by themselves">+ sub-pages</span> @endif
                                @if ($row['mega']) <span class="nav-badge nav-badge--mega">Wide panel</span> @endif
                                @if ($row['hidden']) <span class="nav-badge nav-badge--quiet">Not published</span> @endif
                            </span>
                            <button type="button" class="btn btn--quiet nav-row__edit" wire:click="edit('{{ $row['key'] }}')"
                                    aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="drawer-{{ $row['key'] }}">{{ $isOpen ? 'Done' : 'Edit' }}</button>
                        </div>

                        @if ($isOpen)
                            @include('livewire.admin.partials.menu-drawer', ['row' => $row])
                        @endif
                    </li>
                @endforeach
                <li class="nav-list__drop" x-show="line" x-cloak :style="line && `top: ${line.top}px; left: ${line.left + 4}px`" aria-hidden="true"></li>
            </ol>
        @endif

        {{-- The real site, wearing the unsaved menu --}}
        <div class="menu-live" wire:ignore
             x-data="{
                wide: true,
                src: @js($previewUrl),
                stamp: 0,
                get url() { const [base, hash] = this.src.split('#'); return base + '&v=' + this.stamp + (hash ? '#' + hash : ''); },
                noBar(frame) {
                    // The preview scrolls, but without a scrollbar eating into the narrow frame.
                    try {
                        const style = frame.contentDocument.createElement('style');
                        style.textContent = 'html { scrollbar-width: none } html::-webkit-scrollbar { display: none }';
                        frame.contentDocument.head.appendChild(style);
                    } catch (e) {}
                },
                fit() { const box = this.$refs.box; if (! box) return; const room = this.$el.clientWidth - 2; box.style.setProperty('--fit', this.wide ? Math.min(1, room / 1280) : Math.min(.8, room / 390)); },
             }"
             x-init="fit(); new ResizeObserver(() => fit()).observe($el)"
             x-on:menu-preview.window="stamp = Date.now()">
            <div class="menu-live__head">
                <h2 class="heading heading--sub">Live preview</h2>
                <div class="filters" role="group" aria-label="Preview width">
                    <button type="button" class="pill" x-on:click="wide = true; $nextTick(() => fit())" :aria-pressed="wide.toString()">Wide</button>
                    <button type="button" class="pill" x-on:click="wide = false; $nextTick(() => fit())" :aria-pressed="(! wide).toString()">Phone</button>
                </div>
            </div>
            <p class="field__hint" style="margin: 6px 0 0">Your home page with this menu in it, before you save. Only you see it. Hover or tap the tabs to try the drop-downs.</p>
            <div class="menu-live__box" x-ref="box" :class="{ 'menu-live__box--phone': ! wide }">
                <iframe :src="url" x-on:load="noBar($event.target)" :title="'Preview of the site at ' + (wide ? 'full' : 'phone') + ' width'" loading="lazy"></iframe>
            </div>
        </div>

        <div style="margin-top: auto; padding-top: 28px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
            <div style="display: flex; gap: 12px; align-items: center">
                <button type="button" class="btn btn--ink" wire:click="save">Save menu</button>
                @if ($dirty) <span class="saved-note">Unsaved changes</span> @endif
            </div>
            <x-admin.folio side="right" style="margin: 0; padding: 0" />
        </div>
    </section>
</div>
