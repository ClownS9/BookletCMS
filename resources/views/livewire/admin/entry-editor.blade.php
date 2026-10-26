@php
    $main = collect($this->fields['main']);
    $titleField = $main->firstWhere('handle', 'title');
    $bodyField = $main->firstWhere('handle', 'content');
    $otherMain = $main->reject(fn ($f) => in_array($f['handle'], ['title', 'content']) || in_array($f['handle'], $hidden, true));
@endphp

<form wire:submit="save" style="display: contents">
    {{-- Left page: the writing --}}
    <section class="page page--left page--wide-left"
             x-data="writingDesk({ key: @js('booklet.draft.'.($entryId ?? 'new-'.$collection)), savedAt: @js($savedAt), others: @js($coEditors) })"
             x-on:keydown="shortcut($event)">
        <h1 class="sr-only">{{ $entryId ? 'Editing: '.(($values['title'] ?? '') ?: 'Untitled') : 'New '.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::singular($this->model->title())) }}</h1>
        <div class="toolbar">
            <a href="{{ route('admin.entries', $collection) }}" class="back" wire:navigate>
                <x-admin.icon name="back" size="16" /> {{ $this->model->title() }}
            </a>
            @if ($bodyField)
                <div class="format-bar" role="toolbar" aria-label="Formatting">
                    <button type="button" x-on:click="format('heading')" title="Heading" aria-label="Heading"><span class="serif" style="font-weight: 600">H</span></button>
                    <button type="button" x-on:click="format('bold')" title="Bold (Ctrl/⌘ B)" aria-label="Bold"><strong>B</strong></button>
                    <button type="button" x-on:click="format('italic')" title="Italic (Ctrl/⌘ I)" aria-label="Italic"><em class="serif">I</em></button>
                    <button type="button" x-on:click="format('link')" title="Link (Ctrl/⌘ K)" aria-label="Link">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>
                    </button>
                    <button type="button" x-on:click="format('list')" title="List" aria-label="List">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg>
                    </button>
                    <button type="button" x-on:click="format('quote')" title="Quote" aria-label="Quote">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M7 7h4v4c0 3-2 5-4 6M15 7h4v4c0 3-2 5-4 6"/></svg>
                    </button>
                    @if ($stacyHelp)
                        <button type="button" class="format-bar__stacy" x-on:click="pen()" :aria-expanded="help.open ? 'true' : 'false'"
                                title="Ask Stacy to help with the words (Ctrl/⌘ J)" aria-label="Ask Stacy to help with the words">
                            <x-admin.icon name="stacy" size="15" /> <span>Stacy</span>
                        </button>
                    @endif
                </div>
            @endif
            <span class="saved-note" wire:loading.remove wire:target="save" x-show="!autoLabel">
                @if ($savedAt) Saved {{ \Illuminate\Support\Carbon::parse($savedAt)->diffForHumans() }} @elseif ($entryId) Saved @else Not saved yet @endif
            </span>
            <span class="saved-note" wire:loading.remove wire:target="save" x-show="autoLabel" x-cloak
                  :class="{ 'saved-note--warn': auto === 'manual', 'saved-note--busy': auto === 'saving' }" x-text="autoLabel" aria-live="polite"></span>
            <span class="saved-note" wire:loading wire:target="save">Saving…</span>
        </div>

        <p class="desk-note desk-note--offline" x-show="!online" x-cloak role="status">
            You’re offline. Your words are safe on this page and will save when you’re back.
        </p>
        <div class="desk-note" x-show="backup" x-cloak role="status">
            <span>There are unsaved words from earlier in this browser.</span>
            <button type="button" class="link-button" x-on:click="restore()"><strong>Bring them back</strong></button>
            <button type="button" class="link-button" x-on:click="dismiss()">Forget them</button>
        </div>
        <div class="desk-note desk-note--newer" x-show="newer" x-cloak role="alert">
            <span><strong x-text="newer?.name"></strong> saved a newer version <span x-text="newer?.ago"></span>. Saving now would replace their changes.</span>
            <button type="button" class="link-button" wire:click="reloadLatest"><strong>Load their version</strong></button>
            <button type="button" class="link-button" x-on:click="newer = null">Keep mine</button>
        </div>
        <div class="desk-note desk-note--others" x-show="others.length" x-cloak role="status">
            <span class="presence-dots" aria-hidden="true"><template x-for="o in others" :key="o.name"><i :class="{ 'is-typing': o.typing }" :title="o.name"></i></template></span>
            <span><span x-text="othersText"></span> Every save is kept in History.</span>
            @if ($entryId)
                <a href="{{ route('admin.entries.editions', [$collection, $entryId]) }}" wire:navigate>Compare editions →</a>
            @endif
        </div>

        @if ($titleField)
            <label for="field-title" class="sr-only">{{ $titleField['display'] }}</label>
            <input id="field-title" type="text" class="title-input" wire:model.live.debounce.400ms="values.title" placeholder="Untitled" style="margin-top: 32px" autofocus>
            @error('values.title') <p class="field__error">{{ $message }}</p> @enderror
        @endif

        <p class="serif muted" style="margin: 8px 0 26px; font-style: italic; font-size: 17px">
            {{ $words }} {{ str('word')->plural($words) }}
            @if ($this->blueprint) · {{ $this->blueprint->title() }} @endif
        </p>

        @if ($bodyField && $stacyHelp)
            {{-- Stacy at the desk: rewrite the selected words, or keep writing --}}
            <div class="pen-card" x-show="help.open" x-cloak x-trap="help.open && !help.busy"
                 x-on:keydown.escape.stop="closePen()" role="dialog" aria-label="Stacy’s writing help"
                 x-transition:enter="pen-in" x-transition:enter-start="pen-in--from">
                <div class="pen-card__head">
                    <span class="pen-card__mark" aria-hidden="true"><x-admin.icon name="stacy" size="15" /></span>
                    <span class="pen-card__what" x-text="penLabel"></span>
                    <button type="button" class="icon-btn" x-on:click="closePen()" aria-label="Close">×</button>
                </div>

                <template x-if="!help.busy && !help.result && !help.error">
                    <div class="pen-card__menu">
                        @foreach ($writeMenu as $how => [$label])
                            <button type="button" class="pen-chip" x-on:click="stacyDo(@js($how))"
                                    @if ($how === 'continue') x-show="true" @else x-show="help.start !== help.end || help.paragraph" @endif>{{ $label }}</button>
                        @endforeach
                    </div>
                </template>

                <div class="pen-card__busy" x-show="help.busy" role="status">
                    <span class="pen-card__dots" aria-hidden="true"><i></i><i></i><i></i></span>
                    <span x-text="help.how === 'continue' ? 'Writing what comes next…' : 'Reworking it…'"></span>
                </div>

                <template x-if="help.error && !help.busy">
                    <div>
                        <p class="pen-card__error" x-text="help.error"></p>
                        <div class="pen-card__do">
                            <button type="button" class="btn btn--sm" x-on:click="stacyDo(help.how)">Try again</button>
                            <button type="button" class="link-button" x-on:click="help.error = ''">Something else</button>
                        </div>
                    </div>
                </template>

                <template x-if="help.result && !help.busy">
                    <div>
                        <p class="pen-card__before" x-show="help.how !== 'continue' && help.original" x-text="help.original"></p>
                        <div class="pen-card__after" x-text="help.result" tabindex="0" aria-label="Stacy’s version"></div>
                        <div class="pen-card__do">
                            <button type="button" class="btn btn--ink btn--sm" x-on:click="accept()" x-text="help.how === 'continue' ? 'Add it' : 'Use this'"></button>
                            <button type="button" class="btn btn--sm" x-on:click="stacyDo(help.how)">Try again</button>
                            <button type="button" class="link-button" x-on:click="help.result = ''">Another way</button>
                        </div>
                    </div>
                </template>
            </div>
            <div class="pen-undo" x-show="help.undo" x-cloak role="status" x-transition.opacity>
                <span>Stacy’s words are in.</span>
                <button type="button" class="link-button" x-on:click="undoPen()"><strong>Undo</strong></button>
            </div>
        @endif

        @if ($bodyField)
            <label for="field-content" class="sr-only">{{ $bodyField['display'] }}</label>
            <textarea id="field-content" class="body-input" wire:model.blur="values.content" placeholder="Start writing…"></textarea>
            @error('values.content') <p class="field__error">{{ $message }}</p> @enderror
        @endif

        @if ($otherMain->isNotEmpty())
            <div style="display: flex; flex-direction: column; gap: 20px; margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--rule)">
                @foreach ($otherMain as $field)
                    @include('livewire.admin.fields.field', ['field' => $field])
                @endforeach
            </div>
        @endif

        @if ($canAddCutouts)
            <div class="cutouts-offer">
                <button type="button" class="btn btn--dashed" wire:click="addCutouts" wire:loading.attr="disabled" wire:target="addCutouts">
                    + Add cut-outs to {{ $this->model->title() }}
                </button>
                <p class="field__hint">Quotes, pictures, buttons, columns, videos and more, tucked in after the body. Adds them to every {{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::singular($this->model->title())) }}; only admins see this.</p>
            </div>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: margin notes --}}
    <aside class="page page--right page--narrow-right margin-notes" aria-label="Entry details" style="--ribbon: {{ $this->model->get('sticker', 'var(--sun)') }}">
        <div class="ribbon" aria-hidden="true" style="right: 44px"></div>

        <div class="ribbon-clear" style="min-height: 44px; display: flex; align-items: center; justify-content: space-between; gap: 12px">
            <span class="eyebrow">Margin notes</span>
            @if ($entryId)
                <a href="{{ route('admin.entries.editions', [$collection, $entryId]) }}" class="back" style="color: var(--ink)" wire:navigate>
                    <x-admin.icon name="history" size="16" /> History · {{ $this->editions }} {{ str('edition')->plural($this->editions) }}
                </a>
            @endif
        </div>

        <fieldset style="margin: 0; padding: 0; border: 0">
            <legend class="field__label" style="padding: 0; margin-bottom: 10px">Status</legend>
            <div class="statuses" role="radiogroup" aria-label="Status">
                @foreach ($statuses as $value => $label)
                    <button type="button" role="radio" wire:click="setStatus('{{ $value }}')"
                            aria-checked="{{ $status === $value ? 'true' : 'false' }}"
                            @class(['status-pick', 'sticker--'.$value => $status === $value])>{{ $label }}</button>
                @endforeach
            </div>
            @error('status') <p class="field__error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Built of sections, on the page itself --}}
        @if ($build)
            <section class="review-card review-card--built" aria-label="Built on the page">
                <p class="review-card__head">
                    <span @class(['sticker', 'sticker--'.['live' => 'mint', 'changed' => 'sun', 'scheduled' => 'sky', 'draft' => 'sand'][$build['status']]])>Built on the page</span>
                    <span class="review-card__when">{{ $build['words'] }}</span>
                </p>
                <p class="field__hint">Readers see the sections you built{{ $build['status'] === 'draft' ? ' once it’s published' : '' }}. {{ $build['fresh']
                    ? 'Its words here are made from them each time you publish, and show only if you stop building it.'
                    : 'Its words here show only if you stop building it.' }}</p>
                <span class="review-card__go">
                    <a class="btn btn--ink" href="{{ $build['url'] }}">Edit it on the page</a>
                    <a class="link-button" href="{{ route('admin.help.building') }}" wire:navigate>How building pages works</a>
                </span>
            </section>
        @elseif ($canBuild)
            <section class="review-card" aria-label="Build it on the page">
                <p class="review-card__head">Build it on the page</p>
                <p class="field__hint">Make it of sections (a hero, cards, prices, questions…), right where readers see it. Its words and pictures are where you start; nothing changes for readers until you publish.</p>
                <span class="review-card__go">
                    <button type="button" class="btn" wire:click="buildOnPage" wire:confirm="Save, then open it on the site to build it?">Build it on the page</button>
                    <a class="link-button" href="{{ route('admin.help.building') }}" wire:navigate>How it works</a>
                </span>
            </section>
        @endif

        {{-- Review before publishing --}}
        @if (! $reviewPublished && (! $canPublish || $review))
            <section @class(['review-card', 'review-card--'.($review ?? 'none')]) aria-label="Review">
                @if ($review)
                    <p class="review-card__head">
                        @if ($review === 'waiting')
                            <span class="sticker sticker--sky">In review</span>
                            {{ $canPublish ? ($reviewInfo['by']?->name ?? 'A writer').' asked for a review' : 'Waiting for an editor' }}
                        @else
                            <span class="sticker sticker--rose">Sent back</span>
                            by {{ $reviewInfo['by']?->name ?? 'an editor' }}
                        @endif
                        @if ($reviewInfo['at']) <span class="review-card__when">· {{ $reviewInfo['at']->diffForHumans() }}</span> @endif
                    </p>
                    @if ($reviewInfo['note'] !== '')
                        <blockquote class="review-card__note">{{ $reviewInfo['note'] }}</blockquote>
                    @endif
                @endif

                @if ($canPublish && $review === 'waiting')
                    <p class="field__hint">Publish it with the status above, or send it back with what to change.</p>
                    <label for="back-note" class="sr-only">What to change</label>
                    <textarea id="back-note" class="input" rows="3" wire:model="backNote" placeholder="What to change before it goes out"></textarea>
                    @error('backNote') <p class="field__error">{{ $message }}</p> @enderror
                    <button type="button" class="btn btn--sm" wire:click="sendBack" wire:loading.attr="disabled" wire:target="sendBack">Send back</button>
                @elseif (! $canPublish && $review === 'waiting')
                    <p class="field__hint">You can keep editing: they’ll read your latest saved words.</p>
                    <button type="button" class="link-button review-card__undo" wire:click="withdrawReview">Take it back</button>
                @elseif (! $canPublish)
                    @if (! $entryId)
                        <p class="field__hint">Writers save drafts; an editor or admin publishes them. Once it’s saved, you can ask for a review.</p>
                    @else
                        <p class="field__hint">{{ $review === 'returned' ? 'Made the changes? Ask again.' : 'Writers save drafts; an editor or admin publishes them. Ready? Ask for a review.' }}</p>
                        <label for="review-note" class="sr-only">A note for the editor</label>
                        <textarea id="review-note" class="input" rows="2" wire:model="reviewNote" placeholder="A note for the editor (optional)"></textarea>
                        @error('reviewNote') <p class="field__error">{{ $message }}</p> @enderror
                        <button type="button" class="btn btn--ink btn--sm" wire:click="askReview" wire:loading.attr="disabled" wire:target="askReview">
                            {{ $review === 'returned' ? 'Ask for review again' : 'Ask for review' }}
                        </button>
                    @endif
                @endif
            </section>
        @endif

        @if ($this->model->dated())
            <div class="field">
                <label for="entry-date">{{ $status === 'scheduled' ? 'Goes out on' : 'Date' }}</label>
                <input id="entry-date" type="datetime-local" wire:model="date">
                @error('date') <p class="field__error">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="field">
            <label for="entry-slug">Slug</label>
            <div class="slug">
                <span class="muted">{{ $routePrefix ?: '/' }}</span>
                <input id="entry-slug" type="text" wire:model.blur="slug" placeholder="made-from-the-title">
            </div>
            @error('slug') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        @foreach ($this->fields['side'] as $field)
            @continue(in_array($field['handle'], $hidden, true))
            @continue($searchCard && in_array($field['handle'], ['meta_title', 'meta_description'], true)) {{-- in the Search & sharing card --}}
            @include('livewire.admin.fields.field', ['field' => $field])
        @endforeach

        @if ($searchCard)
            @include('livewire.admin.partials.search-card')
        @endif

        {{-- New posts by email --}}
        @if ($newsletter && ! $translationOf)
            <div class="field">
                @if ($emailed)
                    <span class="field__label">Email to subscribers</span>
                    <p class="field__hint" style="margin: 0">
                        @if ($emailed->status === 'skipped') Not emailed. @else Sent to {{ number_format($emailed->recipients) }} {{ str('reader')->plural($emailed->recipients) }} on {{ $emailed->sent_at?->format('j M, g:ia') }}. @endif
                    </p>
                @else
                    <label class="check" style="min-height: 32px">
                        <input type="checkbox" wire:model="emailIt">
                        Email it to {{ $subscriberCount ? number_format($subscriberCount).' '.str('subscriber')->plural($subscriberCount) : 'subscribers' }} when it goes out
                    </label>
                @endif
            </div>
        @endif

        {{-- Other languages: translations of this entry, and making a new one --}}
        @if ($translations->isNotEmpty() || $canTranslate)
            <div class="field languages" x-data="{ picking: false, lang: '' }">
                <span class="field__label">Languages</span>
                @if ($translations->isNotEmpty())
                    <ul class="languages__list">
                        @foreach ($translations as $other)
                            <li wire:key="lang-{{ $other->id() }}">
                                <a href="{{ route('admin.entries.edit', [$other->collectionHandle(), $other->id()]) }}" wire:navigate>
                                    <span class="languages__name">{{ $other->id() === $translationOf ? 'Original' : (\App\Stacy\Writer::LANGUAGES[$other->get('lang')][1] ?? strtoupper((string) $other->get('lang'))) }}</span>
                                    <span class="languages__title">{{ $other->title() ?: 'Untitled' }}</span>
                                    <span class="sticker sticker--sm sticker--{{ $other->status() }}" style="--tilt: -2deg">{{ ucfirst($other->status()) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canTranslate)
                    <button type="button" class="btn btn--dashed btn--sm" x-show="!picking" x-on:click="picking = true" style="align-self: flex-start">
                        <x-admin.icon name="stacy" size="14" /> Translate with Stacy…
                    </button>
                    <div class="languages__pick" x-show="picking" x-cloak>
                        <label for="translate-to" class="sr-only">Translate into</label>
                        <select id="translate-to" x-model="lang">
                            <option value="">Into…</option>
                            @foreach (\App\Stacy\Writer::LANGUAGES as $code => [$english, $native])
                                <option value="{{ $code }}">{{ $english }}{{ $native !== $english ? ' · '.$native : '' }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn--ink btn--sm" x-bind:disabled="!lang" x-on:click="$wire.translateTo(lang)"
                                wire:loading.attr="disabled" wire:target="translateTo">
                            <span wire:loading.remove wire:target="translateTo">Make a draft</span>
                            <span wire:loading wire:target="translateTo">Translating…</span>
                        </button>
                        <p class="field__hint">Stacy translates the last saved version into a new draft beside this one, with the same pictures. Long posts take a minute.</p>
                    </div>
                @endif
            </div>
        @endif

        {{-- How it's being read --}}
        @if ($reading)
            @php
                $peak = max(1, max(array_column($reading['days'], 'views')));
                $change = $reading['previous'] > 0 ? (int) round(($reading['views'] - $reading['previous']) / $reading['previous'] * 100) : null;
            @endphp
            <section class="reading" aria-labelledby="reading-title">
                <div class="reading__head">
                    <h2 id="reading-title" class="field__label">Readers</h2>
                    <span class="reading__period">last 30 days</span>
                </div>
                @if ($reading['total'] === 0)
                    <p class="field__hint">No visits yet. Share it, and the numbers turn up here (counted without cookies).</p>
                @else
                    <dl class="reading__numbers">
                        <div><dt>Views</dt><dd>{{ number_format($reading['views']) }}</dd></div>
                        <div><dt>Readers</dt><dd>{{ number_format($reading['readers']) }}</dd></div>
                        <div><dt>Read to the end</dt><dd>{{ $reading['rate'] !== null ? $reading['rate'].'%' : '—' }}</dd></div>
                    </dl>
                    <div class="spark reading__spark" role="img" aria-label="Views each day for 30 days: {{ number_format($reading['views']) }} in all{{ $reading['best'] ? ', most on '.\Illuminate\Support\Carbon::parse($reading['best']['day'])->format('j F').' ('.$reading['best']['views'].')' : '' }}">
                        @foreach ($reading['days'] as $day)
                            <span class="spark__bar" style="--h: {{ max(4, round($day['views'] / $peak * 100)) }}%" title="{{ \Illuminate\Support\Carbon::parse($day['day'])->format('D j M') }}: {{ $day['views'] }}"></span>
                        @endforeach
                    </div>
                    @if ($reading['sources'] !== [])
                        <p class="reading__line"><span>Came from</span>
                            {{ collect($reading['sources'])->map(fn ($s) => $s['source'].' '.number_format($s['views']))->implode(' · ') }}</p>
                    @endif
                    <p class="reading__line reading__line--faint">
                        @if ($change !== null) {{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}% on the 30 days before · @endif
                        {{ number_format($reading['total']) }} {{ str('view')->plural($reading['total']) }} since {{ \Illuminate\Support\Carbon::parse($reading['since'])->format('j M Y') }}
                    </p>
                    @if ($reading['rate'] === null && $reading['views'] > 0)
                        <p class="field__hint">“Read to the end” shows after a few more visits.</p>
                    @endif
                @endif
                @if (in_array(request()->user()->level(), ['admin', 'editor'], true))
                    <a href="{{ route('admin.visits') }}" class="back reading__more" wire:navigate>All visits →</a>
                @endif
            </section>
        @endif

        @if ($this->linkedFrom->isNotEmpty())
            <section class="linked-from" aria-labelledby="linked-from-title">
                <h2 id="linked-from-title" class="field__label">Linked from</h2>
                <ul role="list">
                    @foreach ($this->linkedFrom->take(8) as $other)
                        <li><a href="{{ route('admin.entries.edit', [$other->collectionHandle(), $other->id()]) }}" wire:navigate style="--spine: {{ $other->collection()?->get('sticker', 'var(--sand)') ?? 'var(--sand)' }}">{{ $other->title() ?: 'Untitled' }}</a></li>
                    @endforeach
                </ul>
                @if ($this->linkedFrom->count() > 8) <p class="field__hint">and {{ $this->linkedFrom->count() - 8 }} more.</p> @endif
            </section>
        @endif

        @if ($entryId)
            <livewire:admin.entry-notes :entry-id="$entryId" :key="'notes-'.$entryId" />
            <livewire:admin.proof-copies :entry-id="$entryId" :key="'proofs-'.$entryId" />
        @else
            <p class="field__hint">Once saved, you can stick notes for the team on this {{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::singular($this->model->title())) }}.</p>
        @endif

        <div style="margin-top: auto; padding-top: 18px; border-top: 1px solid var(--rule); display: flex; gap: 10px; flex-wrap: wrap">
            <button type="submit" class="btn btn--ink btn--lg" style="flex: 1 1 auto" wire:loading.attr="disabled" wire:target="save">{{ $primaryLabel }}</button>
            @if ($entryId)
                <a href="{{ route('preview', $entryId) }}" class="btn" target="_blank" rel="noopener">Preview</a>
            @endif
        </div>

        @if ($entryId)
            <button type="button" class="btn btn--danger" style="align-self: flex-start"
                    wire:click="delete" wire:confirm="{{ $this->linkedFrom->isNotEmpty() ? $this->linkedFrom->count().' '.str('page')->plural($this->linkedFrom->count()).' link here, and the links will stop showing. ' : '' }}Tear this entry out of the book? It waits in the bin for 30 days, so you can put it back.">Delete</button>
        @endif

        <x-admin.folio side="right" style="padding-top: 0" />
    </aside>

    @if ($linking !== null)
        <div class="picker" role="dialog" aria-modal="true" aria-labelledby="shelf-title"
             x-data x-init="$nextTick(() => $el.querySelector('input[type=search]')?.focus())"
             x-on:keydown.escape.window="$wire.closeShelf()">
            <div class="picker__backdrop" wire:click="closeShelf"></div>
            <div class="picker__sheet">
                <div class="picker__head">
                    <h2 id="shelf-title" class="heading" style="font-size: 26px">Link from the shelf</h2>
                    <button type="button" class="btn btn--ink btn--sm" wire:click="closeShelf">Done</button>
                </div>
                <div class="search">
                    <x-admin.icon name="search" size="16" />
                    <label for="shelf-search" class="sr-only">Find a post or page</label>
                    <input id="shelf-search" type="search" wire:model.live.debounce.250ms="linkSearch" placeholder="Find by title">
                </div>
                @if ($this->shelfEntries->isEmpty())
                    <p class="empty-note">{{ $linkSearch === '' ? 'Nothing here to link to yet.' : 'Nothing with “'.$linkSearch.'” in the title.' }}</p>
                @else
                    <ul class="shelf" role="list">
                        @foreach ($this->shelfEntries as $i => $candidate)
                            <li wire:key="shelf-{{ $candidate->id() }}">
                                <button type="button" class="shelf__spine" wire:click="linkEntry(@js($candidate->id()))"
                                        style="--spine: {{ $candidate->collection()?->get('sticker', 'var(--sand)') ?? 'var(--sand)' }}; --tilt: {{ ['-0.6deg', '0.4deg', '-0.2deg', '0.7deg'][$i % 4] }}">
                                    <span class="shelf__title">{{ $candidate->title() ?: 'Untitled' }}</span>
                                    <span class="shelf__meta">{{ $candidate->collection()?->title() }}@if ($candidate->date()) · {{ $candidate->date()->format('j M Y') }}@endif</span>
                                    @if ($candidate->status() !== 'published')
                                        <span class="sticker sticker--sm sticker--{{ $candidate->status() }}" style="--tilt: -2deg">{{ ucfirst($candidate->status()) }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    @if ($picking !== null)
        <div class="picker" role="dialog" aria-modal="true" aria-labelledby="picker-title"
             x-data x-init="$nextTick(() => $el.querySelector('input[type=search]')?.focus())"
             x-on:keydown.escape.window="$wire.closePicker()">
            <div class="picker__backdrop" wire:click="closePicker"></div>
            <div class="picker__sheet">
                <div class="picker__head">
                    <h2 id="picker-title" class="heading" style="font-size: 26px">{{ $pickingMany ? 'Choose pictures' : 'Choose from Media' }}</h2>
                    @if ($pickingMany)
                        <span class="field__hint" style="margin-left: auto">Pick as many as you like; each one joins the cut-out.</span>
                        <button type="button" class="btn btn--ink btn--sm" wire:click="closePicker">Done</button>
                    @else
                        <button type="button" class="icon-btn" wire:click="closePicker" aria-label="Close">×</button>
                    @endif
                </div>
                <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center">
                    <div class="search" style="flex: 1 1 260px">
                        <x-admin.icon name="search" size="16" />
                        <label for="picker-search" class="sr-only">Search Media</label>
                        <input id="picker-search" type="search" wire:model.live.debounce.250ms="pickerSearch" placeholder="Search by name or alt text">
                    </div>
                    @if (request()->user()->canChangeMedia())
                    <label class="btn" style="position: relative; overflow: hidden">
                        <x-admin.icon name="upload" size="16" />
                        <span wire:loading.remove wire:target="pickerUpload">Upload new</span>
                        <span wire:loading wire:target="pickerUpload">Uploading…</span>
                        <input type="file" wire:model="pickerUpload" style="position: absolute; inset: 0; opacity: 0; cursor: pointer">
                    </label>
                    @endif
                </div>
                @error('pickerUpload') <p class="field__error">{{ $message }}</p> @enderror

                @if (empty($this->pickerAssets))
                    <p class="empty-note">Nothing here yet. Upload a picture to tape it in.</p>
                @else
                    <div class="album album--picker">
                        @foreach ($this->pickerAssets as $i => $asset)
                            <button type="button" class="photo" wire:key="pick-{{ md5($asset['path']) }}" wire:click="choose(@js($asset['path']))"
                                    style="--tilt: {{ ['-2deg', '1.5deg', '-1deg', '2deg'][$i % 4] }}" aria-label="{{ $asset['name'] }}">
                                <span class="photo__frame">
                                    @if ($asset['image'])
                                        <img src="{{ $asset['url'] }}" alt="" loading="lazy" decoding="async" @if ($asset['srcset'] ?? '') srcset="{{ $asset['srcset'] }}" sizes="200px" @endif style="object-position: {{ $asset['focus'] ?? '50% 50%' }}">
                                    @else
                                        <span class="photo__type">{{ $asset['ext'] }}</span>
                                    @endif
                                </span>
                                <span class="photo__name">{{ $asset['name'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</form>
