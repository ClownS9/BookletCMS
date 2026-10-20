@php
    $tilts = ['-2deg', '1.5deg', '-1deg', '2deg', '1deg'];
    $typeTones = ['text' => 'sand', 'markdown' => 'sun', 'textarea' => 'sun', 'integer' => 'mint', 'date' => 'lilac', 'assets' => 'sky', 'toggle' => 'lilac', 'select' => 'rose', 'terms' => 'coral', 'link' => 'mint', 'blocks' => 'rose', 'entries' => 'sky', 'grid' => 'mint'];
@endphp

<div style="display: contents">
    {{-- Left page: every chapter --}}
    <section class="page page--left" style="flex-basis: 360px">
        <a href="{{ route('admin.contents') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Contents</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
        <h1 class="display display--page" style="margin-top: 8px">Collections</h1>
        <p class="lede" style="margin: 12px 0 22px; font-size: 15px">Each collection is a chapter of your book with its own fields.</p>

        <ul class="picks">
            @foreach ($collections as $i => $c)
                <li wire:key="collection-{{ $c->handle() }}">
                    <button type="button" class="pick" wire:click="pick('{{ $c->handle() }}')" aria-pressed="{{ $editing === $c->handle() ? 'true' : 'false' }}">
                        <span class="dot" style="--dot: {{ $c->get('sticker', 'var(--sand)') }}; --tilt: {{ $i % 2 ? '6deg' : '-6deg' }}"></span>
                        <span style="min-width: 0">
                            <span class="pick__title" style="font-size: 20px">{{ $c->title() }}</span>
                            <span class="pick__meta">{{ $counts[$c->handle()] ?? 0 }} {{ str('entry')->plural($counts[$c->handle()] ?? 0) }} · {{ $c->dated() ? 'dated' : 'undated' }}</span>
                        </span>
                    </button>
                </li>
            @endforeach
            @if ($editing === null)
                <li>
                    <span class="pick" aria-current="true" style="background: var(--chosen); box-shadow: inset 0 0 0 1px var(--edge-2)">
                        <span class="dot" style="--dot: {{ $sticker }}"></span>
                        <span class="pick__title" style="font-size: 20px">{{ $title !== '' ? $title : 'New chapter' }}</span>
                    </span>
                </li>
            @endif
        </ul>

        @if ($canEdit)
            <button type="button" class="btn btn--wide btn--dashed" style="margin-top: 16px" wire:click="newChapter">+ Bind a new chapter</button>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the blueprint --}}
    <form wire:submit="save" class="page page--right page--stack" style="--ribbon: {{ $sticker }}">
        <div class="ribbon" aria-hidden="true"></div>
        <p class="eyebrow">{{ $editing ? 'Blueprint' : 'A new chapter' }}</p>

        @unless ($canEdit)
            <div class="locked">
                <x-admin.icon name="lock" size="20" />
                <span><strong style="display: block">Only admins can change collections</strong>
                <span style="font-size: 14px; color: var(--muted)">You can look at the blueprint, but not edit it.</span></span>
            </div>
        @endunless

        <fieldset @disabled(! $canEdit) style="margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: 22px; min-width: 0">
            <legend class="sr-only">Collection</legend>

            <div class="three-up">
                <div class="field">
                    <label for="c-title">Name</label>
                    <input id="c-title" type="text" wire:model.live.debounce.300ms="title" class="serif" style="font-size: 19px" placeholder="Recipes">
                    @error('title') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="c-handle">Handle</label>
                    <input id="c-handle" type="text" wire:model.blur="handle" style="font-family: var(--mono); font-size: 13px" @disabled($editing)>
                    @error('handle') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="c-route">Address</label>
                    <input id="c-route" type="text" wire:model="route" style="font-family: var(--mono); font-size: 13px" placeholder="/recipes/{slug}">
                    @error('route') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div style="display: flex; gap: 28px; flex-wrap: wrap; align-items: flex-start">
                <fieldset style="margin: 0; padding: 0; border: 0">
                    <legend class="field__label" style="padding: 0; margin-bottom: 10px">Sticker</legend>
                    <div role="radiogroup" aria-label="Sticker colour" style="display: flex; gap: 8px; flex-wrap: wrap">
                        @foreach ($stickers as $hex)
                            <button type="button" role="radio" class="swatch" style="--swatch: {{ $hex }}; width: 36px; height: 36px; --tilt: {{ $loop->even ? '-4deg' : '4deg' }}"
                                    aria-label="Colour {{ $loop->iteration }}" aria-checked="{{ $sticker === $hex ? 'true' : 'false' }}"
                                    wire:click="$set('sticker', '{{ $hex }}')"></button>
                        @endforeach
                    </div>
                </fieldset>

                <label class="check">
                    <input type="checkbox" wire:model="dated"> Dated, newest first (like a blog)
                </label>

                @if ($allTaxonomies->isNotEmpty())
                    <fieldset style="margin: 0; padding: 0; border: 0">
                        <legend class="field__label" style="padding: 0; margin-bottom: 10px">Uses</legend>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap">
                            @foreach ($allTaxonomies as $taxonomy)
                                <button type="button" class="pill" aria-pressed="{{ in_array($taxonomy->handle(), $taxonomies, true) ? 'true' : 'false' }}"
                                        wire:click="toggleTaxonomy('{{ $taxonomy->handle() }}')">{{ $taxonomy->title() }}</button>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
            </div>

            <div>
                <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 8px">
                    <h2 class="heading" style="font-size: 26px; margin: 0">Fields</h2>
                    <a href="#add-a-field" class="back" style="color: var(--ink)">+ Add a field ↓</a>
                </div>
                <ol class="blueprint">
                    @foreach ($fields as $i => $field)
                        <li wire:key="field-{{ $field['key'] }}" class="blueprint__row">
                            @if ($field['import'])
                                <span style="flex: 1 1 auto"><strong>{{ $fieldsets[$field['import']] ?? $field['import'] }}</strong> <span class="muted" style="font-size: 13px">· a shared set of fields</span></span>
                            @else
                                <div class="blueprint__main">
                                    <label class="sr-only" for="f-display-{{ $field['key'] }}">Field name</label>
                                    <input id="f-display-{{ $field['key'] }}" type="text" class="input" wire:model.blur="fields.{{ $i }}.display" placeholder="Field name">
                                    <label class="sr-only" for="f-handle-{{ $field['key'] }}">Handle</label>
                                    <input id="f-handle-{{ $field['key'] }}" type="text" class="input" style="font-family: var(--mono); font-size: 12px" wire:model.blur="fields.{{ $i }}.handle" placeholder="handle">
                                    @if ($field['type'] === 'select')
                                        <label class="sr-only" for="f-options-{{ $field['key'] }}">Choices</label>
                                        <input id="f-options-{{ $field['key'] }}" type="text" class="input blueprint__wide" wire:model.blur="fields.{{ $i }}.options" placeholder="Choices, separated by commas">
                                    @elseif ($field['type'] === 'entries')
                                        <label class="sr-only" for="f-from-{{ $field['key'] }}">Links to</label>
                                        <select id="f-from-{{ $field['key'] }}" class="input" wire:model="fields.{{ $i }}.from">
                                            <option value="">Links to any chapter</option>
                                            @foreach ($collections as $c)
                                                <option value="{{ $c->handle() }}">Links to {{ $c->title() }}</option>
                                            @endforeach
                                        </select>
                                        <label class="sr-only" for="f-max-{{ $field['key'] }}">Most it holds</label>
                                        <input id="f-max-{{ $field['key'] }}" type="number" min="1" max="{{ \App\Support\Links::MAX }}" class="input" wire:model.blur="fields.{{ $i }}.max" placeholder="Up to (any)">
                                        @error("fields.$i.max") <p class="field__error blueprint__wide">{{ $message }}</p> @enderror
                                    @elseif ($field['type'] === 'grid')
                                        <label class="sr-only" for="f-cols-{{ $field['key'] }}">Columns</label>
                                        <input id="f-cols-{{ $field['key'] }}" type="text" class="input blueprint__wide" wire:model.blur="fields.{{ $i }}.columns" placeholder="Columns, e.g. Amount, Item, Photo (picture)">
                                        <p class="field__hint blueprint__wide" style="margin: 0">Separate with commas. Words by default; add (number), (link), (picture), (yes or no) or (long words) after a name for another kind.</p>
                                    @elseif ($field['type'] === 'terms')
                                        <label class="sr-only" for="f-tax-{{ $field['key'] }}">From</label>
                                        <select id="f-tax-{{ $field['key'] }}" class="input blueprint__wide" wire:model="fields.{{ $i }}.taxonomy">
                                            @foreach ($allTaxonomies as $taxonomy)
                                                <option value="{{ $taxonomy->handle() }}">From {{ $taxonomy->title() }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    @error("fields.$i.display") <p class="field__error blueprint__wide">{{ $message }}</p> @enderror
                                    @error("fields.$i.handle") <p class="field__error blueprint__wide">{{ $message }}</p> @enderror
                                </div>

                                {{-- Show this field only when… (its own line under the field) --}}
                                    @php
                                        $others = $this->whenFields($field['key']);
                                        $when = $field['when'];
                                        $said = $field['when_complex']
                                            ? 'Shown only sometimes (a rule written in its blueprint file)'
                                            : (($when['field'] ?? '') !== '' && isset($others[$when['field']])
                                                ? \App\Support\Conditions::describe(\App\Support\Conditions::fromSimple($when), $others) : null);
                                    @endphp
                                    <div class="when" @if ($field['handle'] === 'title' && ! $said) hidden @endif>
                                        @if (! $field['when_open'])
                                            @if ($said)
                                                <span class="when__said"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg> {{ $said }}</span>
                                                @unless ($field['when_complex']) <button type="button" class="link-button when__link" wire:click="$set('fields.{{ $i }}.when_open', true)">Change</button> @endunless
                                                <button type="button" class="link-button when__link" wire:click="clearWhen({{ $i }})">Always show</button>
                                            @elseif ($others !== [])
                                                <button type="button" class="link-button when__link" wire:click="$set('fields.{{ $i }}.when_open', true)">Show only when…</button>
                                            @endif
                                        @else
                                            @php
                                                $target = $others[$when['field'] ?? ''] ?? null;
                                                $ops = match ($target['type'] ?? null) {
                                                    'toggle' => ['on', 'off'],
                                                    'select' => ['is', 'isnt', 'filled', 'empty'],
                                                    null => [],
                                                    default => ['filled', 'empty', 'contains', 'is'],
                                                };
                                            @endphp
                                            <div class="when__row">
                                                <label class="sr-only" for="when-mode-{{ $field['key'] }}">Show or hide</label>
                                                <select id="when-mode-{{ $field['key'] }}" class="input" wire:model.live="fields.{{ $i }}.when.mode">
                                                    <option value="if">Show when</option>
                                                    <option value="unless">Hide when</option>
                                                </select>
                                                <label class="sr-only" for="when-field-{{ $field['key'] }}">Field</label>
                                                <select id="when-field-{{ $field['key'] }}" class="input" wire:model.live="fields.{{ $i }}.when.field">
                                                    <option value="">pick a field…</option>
                                                    @foreach ($others as $h => $o) <option value="{{ $h }}">{{ $o['display'] }}</option> @endforeach
                                                </select>
                                                @if ($target)
                                                    <label class="sr-only" for="when-op-{{ $field['key'] }}">Is</label>
                                                    <select id="when-op-{{ $field['key'] }}" class="input" wire:model.live="fields.{{ $i }}.when.op">
                                                        <option value="">…</option>
                                                        @foreach ($ops as $op) <option value="{{ $op }}">{{ \App\Support\Conditions::OPS[$op] }}</option> @endforeach
                                                    </select>
                                                    @if (in_array($when['op'] ?? '', ['is', 'isnt', 'contains'], true))
                                                        <label class="sr-only" for="when-value-{{ $field['key'] }}">Value</label>
                                                        @if ($target['type'] === 'select' && $when['op'] !== 'contains')
                                                            <select id="when-value-{{ $field['key'] }}" class="input" wire:model.live="fields.{{ $i }}.when.value">
                                                                <option value="">…</option>
                                                                @foreach ($target['options'] as $k => $label) <option value="{{ $k }}">{{ $label }}</option> @endforeach
                                                            </select>
                                                        @else
                                                            <input id="when-value-{{ $field['key'] }}" type="text" class="input" wire:model.blur="fields.{{ $i }}.when.value" placeholder="words">
                                                        @endif
                                                    @endif
                                                @endif
                                            </div>
                                            <div class="when__row">
                                                <label class="sr-only" for="when-words-{{ $field['key'] }}">Or say it in words</label>
                                                <input id="when-words-{{ $field['key'] }}" type="text" class="input when__words" wire:model="fields.{{ $i }}.when_words"
                                                       wire:keydown.enter.prevent="whenFromWords({{ $i }})" placeholder="Or say it: {{ ($eg = collect($others)->firstWhere('type', 'toggle')) ? '“only when '.\Illuminate\Support\Str::lower($eg['display']).' is on”' : '“only when '.\Illuminate\Support\Str::lower(collect($others)->first()['display'] ?? 'the title').' is filled in”' }}">
                                                <button type="button" class="btn btn--sm" wire:click="whenFromWords({{ $i }})" wire:loading.attr="disabled" wire:target="whenFromWords({{ $i }})">Set it</button>
                                                <button type="button" class="btn btn--sm btn--ink" wire:click="$set('fields.{{ $i }}.when_open', false)">Done</button>
                                            </div>
                                            @if ($said) <p class="field__hint" style="margin: 0">{{ $said }}</p> @endif
                                            @error("fields.$i.when_words") <p class="field__error">{{ $message }}</p> @enderror
                                        @endif
                                    </div>
                                <span class="sticker sticker--{{ $typeTones[$field['type']] ?? 'sand' }}" style="--tilt: {{ $tilts[$i % count($tilts)] }}">{{ $types[$field['type']] ?? $field['type'] }}</span>
                                <label class="check" style="min-height: 40px; font-size: 13px"><input type="checkbox" wire:model="fields.{{ $i }}.required"> Required</label>
                            @endif
                            <label class="sr-only" for="f-tab-{{ $field['key'] }}">Where</label>
                            <select id="f-tab-{{ $field['key'] }}" class="input" style="width: auto; min-height: 40px; font-size: 13px" wire:model="fields.{{ $i }}.tab">
                                <option value="main">Page</option>
                                <option value="sidebar">Margin</option>
                            </select>
                            <span style="display: flex">
                                <button type="button" class="icon-btn" wire:click="move('{{ $field['key'] }}', -1)" aria-label="Move {{ $field['display'] ?: $field['import'] }} up" @disabled($loop->first)>↑</button>
                                <button type="button" class="icon-btn" wire:click="move('{{ $field['key'] }}', 1)" aria-label="Move {{ $field['display'] ?: $field['import'] }} down" @disabled($loop->last)>↓</button>
                                <button type="button" class="icon-btn icon-btn--danger" wire:click="removeField('{{ $field['key'] }}')" aria-label="Remove {{ $field['display'] ?: $field['import'] }}">×</button>
                            </span>
                        </li>
                    @endforeach
                </ol>
                @error('fields') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div>
                <h3 class="eyebrow section-label" id="add-a-field" style="scroll-margin-top: 24px">Add a field</h3>
                <div style="display: flex; flex-wrap: wrap; gap: 10px">
                    @foreach ($types as $type => $label)
                        <button type="button" class="type-sticker sticker--{{ $typeTones[$type] ?? 'sand' }}" style="--tilt: {{ $tilts[$loop->index % count($tilts)] }}" wire:click="addField('{{ $type }}')">+ {{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </fieldset>

        @error('delete') <p class="field__error">{{ $message }}</p> @enderror

        <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
            <div style="display: flex; gap: 10px; flex-wrap: wrap">
                @if ($canEdit)
                    <button type="submit" class="btn btn--ink btn--lg">
                        <span wire:loading.remove wire:target="save">{{ $saved ? 'Saved' : ($editing ? 'Save blueprint' : 'Bind this chapter') }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                    @if ($editing)
                        <a href="{{ route('admin.entries', $editing) }}" class="btn btn--quiet" wire:navigate>Open {{ $title }}</a>
                        <button type="button" class="btn btn--danger" wire:click="delete" wire:confirm="Remove the {{ $title }} chapter and its blueprint? Only works when it has no entries.">Remove</button>
                    @endif
                @endif
            </div>
            <x-admin.folio side="right" style="margin: 0; padding: 0" />
        </div>
    </form>
</div>
