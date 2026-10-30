<div style="display: contents">
    {{-- Left page: the shelf --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Inserts') }}</h1>
            <p class="lede" style="margin-top: 12px; font-size: 15px">{{ __('Extras tucked into the book: a gallery, events, whatever you add. Switch them on and off here.') }}</p>
        </div>

        @if ($safeMode)
            <p class="notice notice--error">{{ __('Safe mode is on (BOOKLET_SAFE_MODE in .env), so every insert is off for now.') }}</p>
        @endif

        @if ($inserts->isEmpty())
            <p class="empty-note">{{ __('No inserts yet. Add one, or make your own with') }} <code>{{ __('php artisan booklet:insert my-insert') }}</code>.</p>
        @else
            <ul class="picks">
                @foreach ($inserts as $i)
                    @php $state = $i->builtIn() ? 'Part of Booklet now' : ($i->manifest('broken') ? 'Couldn’t be read' : ($i->tornOut() ? 'Torn out' : (! $i->fits() ? 'Needs Booklet '.$i->needs() : ($i->enabled() ? 'On' : 'Off')))); @endphp
                    <li wire:key="ins-{{ $i->key }}">
                        <button type="button" class="pick" wire:click="pick('{{ $i->key }}')" aria-pressed="{{ $insert?->key === $i->key && ! $adding && ! $look ? 'true' : 'false' }}" style="align-items: center">
                            <span class="insert-chip" style="--tone: var(--{{ $i->sticker() }})" aria-hidden="true"></span>
                            <span style="flex: 1 1 auto; min-width: 0">
                                <span class="pick__title" style="font-size: 18px">{{ $i->name() }}</span>
                                <span class="pick__meta">{{ $state }} · {{ $i->version() }}</span>
                            </span>
                            @if ($i->updateReady() && ! $i->manifest('broken')) <span class="sticker sticker--sun" style="--tilt: -3deg; font-size: 11px; padding: 2px 8px">{{ __('Update') }}</span> @endif
                            @if ($state === 'On') <span class="svc-light svc-light--on" aria-hidden="true"></span> @endif
                            @if (in_array($state, ['Torn out', 'Couldn’t be read'], true)) <span class="sticker sticker--rose" style="font-size: 11px">!</span> @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="insert-add-start">
            <button type="button" class="btn" wire:click="startAdding" @if ($adding) aria-pressed="true" @endif><x-admin.icon name="plus" size="16" /> {{ __('Add an insert') }}</button>
            <p class="field__hint" style="margin: 0">{{ __('From a .zip, a link, or GitHub. Or make your own with') }} <code>{{ __('php artisan booklet:insert my-insert') }}</code>.</p>
        </div>
        <x-admin.folio side="left" />
    </section>

    {{-- Right page: one insert --}}
    @php $tone = $look ? (in_array($look['sticker'], ['coral', 'sun', 'mint', 'sky', 'lilac', 'rose', 'sand', 'ink'], true) ? $look['sticker'] : 'sand') : ($adding ? 'sand' : ($insert?->sticker() ?? 'sand')); @endphp
    <section class="page page--right page--stack" style="--ribbon: var(--{{ $tone }})">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($look)
            {{-- Fetched and unpacked to one side: what it is, before anything changes --}}
            @php $mb = $look['bytes'] >= 1048576 ? number_format($look['bytes'] / 1048576, 1).' MB' : max(1, round($look['bytes'] / 1024)).' KB'; @endphp
            <div class="ribbon-clear">
                @php $cmp = $look['replaces'] ? version_compare((string) $look['version'], (string) $look['replaces']['version']) : null; @endphp
                <p class="eyebrow" style="margin: 0">{{ $cmp === null ? __('Adding an insert') : ($cmp > 0 ? __('Updating') : __('Replacing')) }}</p>
                <h2 class="heading" style="margin-top: 6px">{{ $look['name'] }}</h2>
                @if ($look['about'] !== '') <p style="margin-top: 10px; font-size: 15px; color: var(--muted)">{{ __($look['about']) }}</p> @endif
            </div>

            <dl class="restore-card__facts">
                <div>
                    <dt>{{ __('Version') }}</dt>
                    <dd>
                        @if ($look['replaces'])
                            {{ $look['replaces']['version'] }} → <strong>{{ $look['version'] }}</strong>
                            @if ($cmp === 0) <span class="muted" style="font-size: 13px">{{ __('(the same version as the one here)') }}</span> @elseif ($cmp < 0) <span class="muted" style="font-size: 13px">{{ __('(older than the one here)') }}</span> @endif
                        @else
                            {{ $look['version'] }}
                        @endif
                    </dd>
                </div>
                @if ($look['author']) <div><dt>{{ __('By') }}</dt><dd>{{ $look['author'] }}</dd></div> @endif
                <div><dt>{{ __('From') }}</dt><dd style="overflow-wrap: anywhere">{{ \App\Inserts\InsertInstaller::describe($look['source']) }}</dd></div>
                <div><dt>{{ __('Size') }}</dt><dd>{{ trans_choice(':count file|:count files', $look['files'], ['count' => number_format($look['files'])]) }}, {{ $mb }}</dd></div>
                @if ($look['needs']) <div><dt>{{ __('Needs') }}</dt><dd>Booklet {{ $look['needs'] }} or newer @if ($look['fits']) <span class="muted" style="font-size: 13px">{{ __('(this is :config)', ['config' => config('booklet.version')]) }}</span> @endif</dd></div> @endif
            </dl>

            @if (! $look['fits'])
                <p class="notice notice--error">{{ __('It needs Booklet :needs or newer, and this is :config, so it can’t be added yet.', ['needs' => $look['needs'], 'config' => config('booklet.version')]) }}</p>
                <button type="button" class="btn btn--quiet" style="align-self: flex-start" wire:click="cancelStaged">{{ __('Not now') }}</button>
            @else
                @if ($look['replaces'] && ($look['replaces']['author'] ?? null) !== $look['author'])
                    <p class="notice notice--error">{{ __('The :name here is by :value; this one is by :value2. Adding it replaces the one here.', ['name' => $look['replaces']['name'], 'value' => $look['replaces']['author'] ?: 'someone unnamed', 'value2' => $look['author'] ?: 'someone unnamed']) }}</p>
                @endif
                <div class="restore-card__warn">
                    <p><strong>{{ __('An insert is code that runs on your site') }}</strong>{{ __(', with the same reach as Booklet itself. Only add inserts from people you trust.') }}</p>
                    <p>
                        @if ($look['replaces'])
                            Its switch, settings and everything it kept stay as they are. The copy here is kept, so you can put it back.
                        @else
                            It arrives switched off, so nothing of it shows until you switch it on.
                        @endif
                        @if ($look['migrations']) It brings tables of its own, made when it’s switched on. @endif
                    </p>
                </div>

                <form wire:submit="install" class="restore-card__go">
                    @if ($this->needsPassword())
                        <div class="field">
                            <label for="insert-password">{{ __('Your password') }}</label>
                            <input id="insert-password" type="password" wire:model="password" autocomplete="current-password" style="max-width: 280px">
                            @error('password') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        @php $confirmedAt = (int) session('auth.password_confirmed_at', 0); @endphp
                            <p class="field__hint" style="margin: 0">No password needed this time: you gave it {{ \Illuminate\Support\Carbon::createFromTimestamp($confirmedAt)->diffForHumans() }}, and Booklet trusts that for {{ (int) round(config('auth.password_timeout', 10800) / 3600) }} hours.</p>
                    @endif
                    @error('install') <p class="field__error">{{ $message }}</p> @enderror
                    <div style="display: flex; gap: 10px; flex-wrap: wrap">
                        <button type="submit" class="btn btn--ink" wire:loading.attr="disabled" wire:target="install">
                            <span wire:loading.remove wire:target="install">{{ $cmp === null ? __('Add to the shelf') : ($cmp > 0 ? 'Update to '.$look['version'] : __('Replace it with this copy')) }}</span>
                            <span wire:loading wire:target="install">{{ $cmp === null ? __('Adding…') : __('Putting it in…') }}</span>
                        </button>
                        <button type="button" class="btn btn--quiet" wire:click="cancelStaged">{{ __('Not now') }}</button>
                    </div>
                </form>
            @endif
            <x-admin.folio side="right" style="margin-top: auto" />
        @elseif ($adding)
            {{-- Adding: from a link, or a file on their computer --}}
            <div class="ribbon-clear">
                <h2 class="heading">{{ __('Add an insert') }}</h2>
                <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">{{ __('Nothing is added until you’ve seen what it is and said yes.') }}</p>
            </div>

            @if ($blocked)
                <p class="notice notice--error">{{ $blocked }}</p>
                <p class="field__hint">{{ __('To add one by hand, put its folder in') }} <code>{{ $folder }}/</code> {{ __('on the server; it shows up here.') }}</p>
                @error('upload') <p class="field__error">{{ $message }}</p> @enderror
            @else
                @include('livewire.admin.partials.catalog', ['kind' => 'insert'])
                <p class="cover-add__or">{{ __('Or, if you were given one') }}</p>
                <form wire:submit="fetch" class="insert-add">
                    <div class="field">
                        <label for="insert-source">{{ __('From a link') }}</label>
                        <input id="insert-source" type="text" wire:model="source" inputmode="url" autocomplete="off" spellcheck="false" placeholder="{{ __('someone/booklet-recipes') }}">
                        <p class="field__hint">{{ __('A GitHub repository (someone/booklet-recipes, or its github.com address), or a link to a .zip.') }}</p>
                        @error('source') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn--ink" style="align-self: flex-start" wire:loading.attr="disabled" wire:target="fetch">
                        <span wire:loading.remove wire:target="fetch">{{ __('Look inside') }}</span>
                        <span wire:loading wire:target="fetch">{{ __('Fetching…') }}</span>
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.settings.inserts.upload') }}" enctype="multipart/form-data" class="backup-upload">
                    @csrf
                    <label for="insert-file" class="field__label">{{ __('From a file on your computer') }}</label>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                        <input id="insert-file" type="file" name="insert" accept=".zip,application/zip" required>
                        <button type="submit" class="btn btn--sm">{{ __('Upload and look inside') }}</button>
                    </div>
                    @error('upload') <p class="field__error">{{ $message }}</p> @enderror
                    @error('insert') <p class="field__error">{{ $message }}</p> @enderror
                </form>
            @endif
            <x-admin.folio side="right" style="margin-top: auto" />
        @elseif ($insert)
            <div class="ribbon-clear">
                <h2 class="heading">{{ $insert->name() }}</h2>
                <p style="margin-top: 4px; font-size: 13px; color: var(--faint); overflow-wrap: anywhere">Version {{ $insert->version() }}@if ($insert->author()) · by {{ $insert->author() }}@endif · @if ($insert->source()) from {{ \App\Inserts\InsertInstaller::describe($insert->source()) }} @else <code>{{ $folder }}/{{ $insert->key }}</code> @endif</p>
                @if ($insert->about() !== '') <p style="margin-top: 10px; font-size: 15px; color: var(--muted)">{{ $insert->about() }}</p> @endif
            </div>

            @if ($insert->manifest('broken') || $insert->tornOut())
                <div class="notice notice--error">
                    <p style="margin: 0; font-weight: 600">{{ $insert->manifest('broken') ? __('Its insert.php couldn’t be read.') : __('Torn out: it broke while loading, so it’s been kept off.') }}</p>
                    @if ($insert->error()) <p style="margin: 6px 0 0; font-family: var(--mono); font-size: 12.5px; overflow-wrap: anywhere">{{ $insert->error() }}</p> @endif
                    <p style="margin: 6px 0 0; font-size: 13px">{{ __('The rest of the book is fine.') }} {{ $previous ? __('It broke after an update, so going back to the earlier copy should fix it.') : __('Fix the file, then try again.') }}</p>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap">
                    @if ($previous)
                        <button type="button" class="btn btn--ink" wire:click="undoUpdate">{{ $previous === $insert->version() ? __('Put the earlier copy back') : 'Undo update (back to '.$previous.')' }}</button>
                    @endif
                    @unless ($insert->manifest('broken'))
                        <button type="button" @class(['btn', 'btn--ink' => ! $previous]) wire:click="retry">{{ __('Try again') }}</button>
                    @endunless
                </div>
            @elseif ($insert->builtIn())
                <p class="notice">This is part of Booklet now: {{ \App\Inserts\InsertManager::BUILT_IN[$insert->key] }}. This copy never runs: once what you built has come across, remove it.</p>
            @elseif (! $insert->fits())
                <p class="notice notice--error">This insert needs Booklet {{ $insert->needs() }} or newer; this is {{ config('booklet.version') }}.</p>
            @else
                <div class="setting-rows">
                    <div class="setting-row">
                        <span>
                            <span id="insert-on" style="display: block; font-size: 15px; font-weight: 500">Use {{ $insert->name() }}</span>
                            <span style="display: block; font-size: 13px; color: var(--faint)">{{ $insert->enabled() ? __('On. Switching off keeps what it has kept.') : __('Off. Nothing of it shows anywhere.') }}</span>
                        </span>
                        <button type="button" class="switch" role="switch" aria-labelledby="insert-on" aria-checked="{{ $insert->enabled() ? 'true' : 'false' }}" wire:click="toggle" @disabled($safeMode)></button>
                    </div>
                </div>
                @if ($insert->error() && $insert->enabled())
                    <p class="field__hint">Last problem ({{ \Illuminate\Support\Carbon::parse($insert->errorAt())->diffForHumans() }}): <span style="font-family: var(--mono); overflow-wrap: anywhere">{{ $insert->error() }}</span></p>
                @endif
            @endif

            @if ($insert->updateReady() && ! $insert->manifest('broken'))
                <div class="insert-update">
                    <span class="sticker sticker--sun" style="--tilt: -3deg">{{ __('New') }}</span>
                    <span style="flex: 1 1 200px; min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">Version {{ $insert->updateReady() }} is ready</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">You have {{ $insert->version() }}. You’ll see what’s in it before anything changes.</span>
                    </span>
                    <button type="button" class="btn btn--sm" wire:click="lookAtUpdate" wire:loading.attr="disabled" wire:target="lookAtUpdate">
                        <span wire:loading.remove wire:target="lookAtUpdate">{{ __('Look at the update') }}</span>
                        <span wire:loading wire:target="lookAtUpdate">{{ __('Fetching…') }}</span>
                    </button>
                </div>
            @endif

            @if ($insert->source() && in_array($insert->source()['type'], ['github', 'url'], true) || ($previous && ! $insert->tornOut()))
                <p class="field__hint insert-keep">
                    @if ($insert->source() && in_array($insert->source()['type'], ['github', 'url'], true))
                        <span>Checked for updates {{ ! $insert->checkedAt() ? 'daily' : (\Illuminate\Support\Carbon::parse($insert->checkedAt())->gt(now()->subMinute()) ? 'just now' : \Illuminate\Support\Carbon::parse($insert->checkedAt())->diffForHumans()) }}.</span>
                        <button type="button" class="link-button" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow">{{ __('Check now') }}</button>
                    @endif
                    @if ($previous && ! $insert->tornOut())
                        <button type="button" class="link-button" wire:click="undoUpdate" wire:confirm="Put back the earlier copy of {{ $insert->name() }} (version {{ $previous }})? Its settings and what it kept stay.">{{ $previous === $insert->version() ? __('Put the earlier copy back') : 'Undo update (back to '.$previous.')' }}</button>
                    @endif
                </p>
            @endif

            @if ($insert->active() && ! $safeMode && ($insert->page() || $insert->slipHelp() !== [] || rescue(fn () => $insert->cards(), [], false) !== []))
                <div class="insert-uses">
                    @if ($insert->page() && $insert->canOpen(request()->user()))
                        <a href="{{ $insert->pageUrl() }}" class="btn" wire:navigate>Open {{ $insert->page()['title'] }} →</a>
                    @endif
                    @php $cardTitles = array_column(rescue(fn () => $insert->cards(), [], false), 'title'); @endphp
                    @if ($cardTitles !== [])
                        <p class="field__hint" style="margin: 0">{{ __('On the') }} <a href="{{ route('admin.contents') }}" wire:navigate>{{ __('Contents') }}</a> {{ __('page, when there’s something to say: :implode', ['implode' => implode(', ', $cardTitles)]) }}</p>
                    @endif
                    @if ($insert->slipHelp() !== [])
                        <p class="field__hint" style="margin: 0">In a post or page: @foreach ($insert->slipHelp() as $line)<code>{{ __($line) }}</code>@if (! $loop->last), @endif @endforeach</p>
                    @endif
                </div>
            @endif

            @php $skills = $insert->manifest('broken') ? [] : rescue(fn () => $insert->skills(), [], false); @endphp
            @if ($skills !== [])
                <div class="skill-list">
                    <h3 class="eyebrow section-label">{{ __('It teaches Stacy') }}</h3>
                    <ul>
                        @foreach ($skills as $skill)
                            <li>
                                <span class="sticker sticker--{{ $skill['changes'] ? 'coral' : 'sky' }}" style="--tilt: 0deg; font-size: 11px; padding: 1px 7px">{{ $skill['changes'] ? __('Changes') : __('Looks up') }}</span>
                                <span>{{ __($skill['label']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="field__hint" style="margin: 0">Changes always wait for a yes. @if (collect($skills)->contains('changes', true)) Switch them off in <a href="{{ route('admin.settings.stacy.behaviour') }}" wire:navigate>{{ __('How Stacy behaves') }}</a>. @endif</p>
                </div>
            @endif

            @if ($insert->settingsSchema() !== [])
                <form wire:submit="saveSettings" class="insert-settings">
                    <h3 class="eyebrow section-label">{{ __('Settings') }}</h3>
                    @foreach ($insert->settingsSchema() as $handle => $f)
                        @if ($f['type'] === 'toggle')
                            <div class="setting-row">
                                <span>
                                    <span id="set-{{ $handle }}" style="display: block; font-size: 15px; font-weight: 500">{{ __($f['label']) }}</span>
                                    @if ($f['hint']) <span style="display: block; font-size: 13px; color: var(--faint)">{{ __($f['hint']) }}</span> @endif
                                </span>
                                <button type="button" class="switch" role="switch" aria-labelledby="set-{{ $handle }}" aria-checked="{{ ($values[$handle] ?? false) ? 'true' : 'false' }}" wire:click="toggleValue('{{ $handle }}')"></button>
                            </div>
                        @else
                            <div class="field">
                                <label for="set-{{ $handle }}">{{ __($f['label']) }}</label>
                                @if ($f['type'] === 'textarea')
                                    <textarea id="set-{{ $handle }}" rows="3" wire:model="values.{{ $handle }}"></textarea>
                                @elseif ($f['type'] === 'select')
                                    <select id="set-{{ $handle }}" wire:model="values.{{ $handle }}">
                                        @foreach ($f['options'] as $value => $label)
                                            <option value="{{ $value }}">{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($f['type'] === 'secret')
                                    @php $hasSecret = ($insert->settings()[$handle] ?? '') !== ''; @endphp
                                    <input id="set-{{ $handle }}" type="password" wire:model="values.{{ $handle }}" autocomplete="off" spellcheck="false"
                                           placeholder="{{ $hasSecret ? __('Saved · type to replace') : '' }}">
                                @else
                                    <input id="set-{{ $handle }}" type="{{ $f['type'] === 'number' ? 'number' : $f['type'] }}" wire:model="values.{{ $handle }}"
                                           @if ($f['min'] !== null) min="{{ $f['min'] }}" @endif @if ($f['max'] !== null) max="{{ $f['max'] }}" @endif>
                                @endif
                                @if ($f['hint']) <p class="field__hint">{{ __($f['hint']) }}</p> @endif
                                @error('values.'.$handle) <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @endforeach
                    <button type="submit" class="btn btn--ink" style="align-self: flex-start">{{ __('Save settings') }}</button>
                </form>
            @endif

            @unless ($insert->manifest('broken'))
                @include('livewire.admin.partials.share-guide', ['kind' => 'insert', 'key' => $insert->key, 'name' => $insert->name(), 'version' => $insert->version(), 'about' => $insert->about(), 'code' => true, 'from' => $insert->source()])
            @endunless

            <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding-top: 18px">
                @if ($records > 0 || $insert->enabled() || ! $blocked)
                    <button type="button" class="link-button" style="color: var(--danger); text-decoration: underline; min-height: 32px; padding: 0"
                            wire:click="forgetInsert(true)"
                            wire:confirm="{{ trans_choice($blocked ? 'Remove :name and delete everything it kept (:count record)? This can’t be undone.|Remove :name and delete everything it kept (:count records)? This can’t be undone.' : 'Remove :name from the shelf and delete everything it kept (:count record)? This can’t be undone.|Remove :name from the shelf and delete everything it kept (:count records)? This can’t be undone.', $records, ['name' => $insert->name()]) }}">{{ __('Remove it, and what it kept') }}</button>
                @else
                    <span></span>
                @endif
                <x-admin.folio side="right" style="margin: 0; padding: 0" />
            </div>
        @else
            <p class="empty-note" style="max-width: 460px">{{ __('Inserts you add show up on the left.') }}</p>
            <x-admin.folio side="right" />
        @endif
    </section>
</div>
