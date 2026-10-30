@php
    $when = fn (string $job) => isset($last[$job]) ? \Illuminate\Support\Carbon::parse($last[$job]['at'])->diffForHumans() : null;
    $lastLine = function (string $job) use ($last, $when) {
        if (! isset($last[$job])) {
            return __('Not run yet.');
        }
        $who = $last[$job]['by'] ?? (! empty($last[$job]['terminal']) ? __('the terminal') : null);
        $by = $who ? ' · '.$who : '';

        return __('Last run :when', ['when' => $when($job)]).$by;
    };
@endphp
<div style="display: contents" @if ($picturesBusy) wire:poll.4s @endif>
    {{-- Left page: keeping it quick and tidy, and starting again --}}
    <section class="page page--left page--stack" style="flex-basis: 440px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ __('Upkeep') }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Upkeep') }}</h1>
                <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('Looking after the book: clear what’s safe to clear, make it quick, remake pictures, tidy how things are written, or start again. Each one is also a command:') }} <code>php booklet</code></p>
            </div>
        </div>

        <x-admin.card>
            <h2 class="heading upkeep-title">{{ __('Speed') }}</h2>
            <div class="upkeep-row">
                <span class="upkeep-row__text">
                    <strong>{{ __('Clear the caches') }}</strong>
                    <small>{{ __('Kept pages, compiled templates and cached settings, made again as needed. After changing theme files or .env, or when something looks out of date. Booklet’s own notes are kept.') }}</small>
                    <small class="upkeep-row__last">{{ $lastLine('clear') }} · <code>php booklet cache:clear</code></small>
                </span>
                <button type="button" class="btn" wire:click="clearCaches" wire:loading.attr="disabled" wire:target="clearCaches">
                    <span wire:loading.remove wire:target="clearCaches">{{ __('Clear') }}</span>
                    <span wire:loading wire:target="clearCaches">{{ __('Clearing…') }}</span>
                </button>
            </div>
            <div class="upkeep-row">
                <span class="upkeep-row__text">
                    <strong>{{ __('Make it quick') }}</strong>
                    <small>{{ __('Prepares settings, routes and templates ahead, for a live server. Run it again (or clear the caches) after changing .env.') }}</small>
                    <small class="upkeep-row__last">{{ $cached ? __('Prepared now.') : __('Not prepared.') }} {{ $lastLine('optimize') }} · <code>php booklet optimize</code></small>
                </span>
                <button type="button" class="btn" wire:click="optimize" wire:loading.attr="disabled" wire:target="optimize">
                    <span wire:loading.remove wire:target="optimize">{{ __('Prepare') }}</span>
                    <span wire:loading wire:target="optimize">{{ __('Preparing…') }}</span>
                </button>
            </div>
        </x-admin.card>

        <x-admin.card>
            <h2 class="heading upkeep-title">{{ __('Pictures') }}</h2>
            <div class="upkeep-row">
                <span class="upkeep-row__text">
                    <strong>{{ __('Remake every picture’s smaller copies') }}</strong>
                    <small>{{ $picturesAvailable ? __('Throws away the sized copies and crops, and makes them again. A big Media can take a few minutes; you can carry on meanwhile.') : __('This server can’t make WebP pictures, so the original pictures are used. Remaking clears the old copies only.') }}</small>
                    <small class="upkeep-row__last">{{ $picturesBusy ? __('Remaking now…') : $lastLine('pictures') }} · <code>php booklet pictures:rebuild</code></small>
                </span>
                <button type="button" class="btn" wire:click="rebuildPictures" @disabled($picturesBusy)>
                    {{ $picturesBusy ? __('Remaking…') : __('Remake') }}
                </button>
            </div>
        </x-admin.card>

        <x-admin.card>
            <h2 class="heading upkeep-title">{{ __('Tidy the writing') }}</h2>
            <div class="upkeep-row">
                <span class="upkeep-row__text">
                    <strong>{{ __('Tidy how posts and pages are written') }}</strong>
                    <small>{{ __('Stray spaces, empty lines, invisible characters pasted in from elsewhere, and headings that skip a step. Code is left exactly as it is. You see every change first, and each earlier version stays in History.') }}</small>
                    <small class="upkeep-row__last">{{ $lastLine('tidy') }} · <code>php booklet tidy</code></small>
                </span>
                @unless ($tidying)
                    <button type="button" class="btn" wire:click="lookTidy" wire:loading.attr="disabled" wire:target="lookTidy">
                        <span wire:loading.remove wire:target="lookTidy">{{ __('Look first') }}</span>
                        <span wire:loading wire:target="lookTidy">{{ __('Looking…') }}</span>
                    </button>
                @endunless
            </div>

            @if ($tidying)
                @if ($preview === [])
                    <p class="empty-note">{{ __('Everything is tidy already.') }}</p>
                    <button type="button" class="btn btn--quiet btn--sm" style="align-self: flex-start" wire:click="closeTidy">{{ __('Close') }}</button>
                @else
                    <fieldset class="upkeep-tidy">
                        <legend>{{ trans_choice(':count would change. Untick any to leave as it is.|:count would change. Untick any to leave as they are.', count($preview)) }}</legend>
                        <ul role="list">
                            @foreach (array_slice($preview, 0, 100) as $change)
                                @php $e = $change['entry']; @endphp
                                <li wire:key="tidy-{{ $e->id() }}">
                                    <label class="check">
                                        <input type="checkbox" value="{{ $e->id() }}" wire:model="tidyPick">
                                        <span>
                                            <strong>{{ $e->title() ?: __('Untitled') }}</strong>
                                            <small>{{ __($e->collection()?->title() ?? '') }} · {{ implode(', ', array_map(fn ($r) => __($rules[$r]), $change['rules'])) }}</small>
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        @if (count($preview) > 100)
                            <p class="field__hint">{{ __('…and :count more, tidied too unless you use php booklet tidy --only=… to choose.', ['count' => count($preview) - 100]) }}</p>
                        @endif
                    </fieldset>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap">
                        <button type="button" class="btn btn--ink" wire:click="applyTidy" wire:loading.attr="disabled" wire:target="applyTidy">
                            {{ trans_choice('Tidy :count|Tidy :count', count($tidyPick), ['count' => count($tidyPick)]) }}
                        </button>
                        <button type="button" class="btn btn--quiet" wire:click="closeTidy">{{ __('Not now') }}</button>
                    </div>
                @endif
            @endif
        </x-admin.card>

        <x-admin.card>
            <h2 class="heading upkeep-title" id="start-again">{{ __('Start again') }}</h2>
            <p class="field__hint" style="margin: 0">{{ __('Clear the parts you choose, or everything for a fresh start, as a new install. A backup is made first, so you can bring the book back from Backups.') }}</p>
            <fieldset class="upkeep-tidy upkeep-reset" aria-describedby="start-again">
                <legend>{{ __('What should go') }}</legend>
                <ul role="list">
                    @foreach ($parts as $key => $part)
                        <li wire:key="part-{{ $key }}">
                            <label class="check">
                                <input type="checkbox" value="{{ $key }}" wire:model.live="resetParts" @disabled($resetEverything)>
                                <span>
                                    <strong>{{ __($part['label']) }} @unless ($part['backup'])<span class="upkeep-reset__lost">{{ __('Not in backups') }}</span>@endunless</strong>
                                    <small>{{ __($part['about']) }}</small>
                                </span>
                            </label>
                        </li>
                    @endforeach
                    <li class="upkeep-reset__all">
                        <label class="check">
                            <input type="checkbox" wire:model.live="resetEverything">
                            <span>
                                <strong>{{ __('Everything, like a new install') }}</strong>
                                <small>{{ __('Every part above, how the book is arranged, and everyone, you too. You go to First setup to begin again.') }}</small>
                            </span>
                        </label>
                    </li>
                </ul>
                @error('resetParts') <p class="field__error">{{ $message }}</p> @enderror
            </fieldset>

            @if ($resetEverything || $resetParts !== [])
                <form wire:submit="startAgain" class="upkeep-reset__confirm">
                    <p class="field__hint" style="margin: 0">
                        @if ($resetEverything)
                            {{ __('Everything goes, and you’re signed out. Theme and insert files, and backups, stay.') }}
                        @else
                            {{ __('Your own account stays. Theme and insert files, and backups, stay.') }}
                        @endif
                    </p>
                    <div class="field">
                        <label for="reset-name">{{ __('Type the site’s name, :name, to go ahead', ['name' => $siteName]) }}</label>
                        <input id="reset-name" type="text" wire:model="resetName" autocomplete="off" spellcheck="false">
                        @error('resetName') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="reset-password">{{ __('Your password') }}</label>
                        <input id="reset-password" type="password" wire:model="resetPassword" autocomplete="current-password">
                        @error('resetPassword') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn--danger" style="align-self: flex-start" wire:loading.attr="disabled" wire:target="startAgain">
                        <span wire:loading.remove wire:target="startAgain">{{ $resetEverything ? __('Back up and empty everything') : __('Back up and start again') }}</span>
                        <span wire:loading wire:target="startAgain">{{ __('Backing up, then clearing…') }}</span>
                    </button>
                </form>
            @endif
            <p class="field__hint" style="margin: 0"><code>php booklet reset</code> · <code>php booklet reset --everything</code></p>
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: readers away, and the commands --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>

        <x-admin.card>
            <h2 class="heading upkeep-title">{{ __('Readers away for a while') }}</h2>
            @if ($away)
                <div class="upkeep-away" role="status">
                    <strong>{{ __('Readers are seeing “back soon”.') }}</strong>
                    <small>
                        @if (! empty($away['since'])) {{ __('Since :when', ['when' => \Illuminate\Support\Carbon::parse($away['since'])->diffForHumans()]) }}@endif
                        @if (! empty($away['by'])) · {{ $away['by'] }}@endif
                        @if (! empty($away['until'])) · {{ __('back by itself :when', ['when' => \Illuminate\Support\Carbon::parse($away['until'])->diffForHumans()]) }}@endif
                    </small>
                    @if (($away['message'] ?? '') !== '') <p>“{{ $away['message'] }}”</p> @endif
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap">
                    <button type="button" class="btn btn--ink" wire:click="welcomeBack">{{ __('Let readers back in') }}</button>
                    <a href="{{ url('/') }}" class="btn" target="_blank" rel="noopener">{{ __('See the site ↗') }}</a>
                </div>
            @else
                <p class="field__hint" style="margin: 0">{{ __('While you move servers or rework the site, readers see a calm “back soon” page. You and everyone else signed in to the book keep working, and still see the site as it is.') }}</p>
                <form wire:submit="sendAway" style="display: flex; flex-direction: column; gap: 12px">
                    <div class="field">
                        <label for="away-message">{{ __('A line for readers') }} <span class="muted">{{ __('(optional)') }}</span></label>
                        <input id="away-message" type="text" wire:model="awayMessage" maxlength="300" placeholder="{{ __('Back by six this evening') }}">
                        @error('awayMessage') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="away-for">{{ __('Let them back in by itself after') }} <span class="muted">{{ __('(minutes, optional)') }}</span></label>
                        <input id="away-for" type="number" min="1" max="10080" wire:model="awayFor" placeholder="60">
                        @error('awayFor') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn" style="align-self: flex-start">{{ __('Show readers “back soon”') }}</button>
                </form>
            @endif
            <p class="field__hint" style="margin: 0"><code>php booklet down "Back by six"</code> · <code>php booklet up</code></p>
        </x-admin.card>

        <x-admin.card>
            <h2 class="heading upkeep-title">{{ __('From the terminal') }}</h2>
            <p class="field__hint" style="margin: 0">{{ __('Everything here, and more, with php booklet. Run it on its own for the list.') }}</p>
            <dl class="upkeep-cli">
                <div><dt><code>php booklet doctor</code></dt><dd>{{ __('The check-up') }}</dd></div>
                <div><dt><code>php booklet backup</code></dt><dd>{{ __('Back up the whole book') }}</dd></div>
                <div><dt><code>php booklet tidy --apply</code></dt><dd>{{ __('Tidy without looking first') }}</dd></div>
                <div><dt><code>php booklet links</code></dt><dd>{{ __('Check every link') }}</dd></div>
                <div><dt><code>php booklet restore</code></dt><dd>{{ __('Bring a backup back') }}</dd></div>
            </dl>
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
