<div style="display: contents">
    <link rel="stylesheet" href="{{ $allFonts }}">
    @if ($fontFaces) <style>{!! $fontFaces !!}</style> @endif

    {{-- Left page: presets, then each choice --}}
    <section class="page page--left page--stack" style="flex-basis: 420px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('The cover') }}</h1>
            </div>
            <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('How the site looks to readers. Start from a cover, then change anything. The preview shows it at once; the site changes only when you press') }} <b>{{ __('Use this cover') }}</b>.</p>
        </div>


        {{-- Themes: Booklet's own, cover packs, and themes with their own templates --}}
        <section class="cover-themes" aria-labelledby="cover-themes-title">
            <div class="cover-themes__head">
                <h2 id="cover-themes-title" class="field__label" style="margin: 0">{{ __('Theme') }}</h2>
                @if ($adding || $waiting)
                    <span class="field__hint cover-facing" style="margin: 0">{{ __('Adding one') }} <span class="cover-facing__wide">{{ __('on the facing page →') }}</span><span class="cover-facing__narrow">{{ __('below ↓') }}</span></span>
                @else
                    {{-- On a phone the facing page is underneath: go to it. --}}
                    <button type="button" class="btn btn--quiet btn--sm" wire:click="startAdding"
                            x-on:click="if (matchMedia('(max-width: 760px)').matches) setTimeout(() => document.getElementById('cover-right')?.scrollIntoView({ behavior: 'smooth' }), 400)">{{ __('+ Add a theme') }}</button>
                @endif
            </div>
            <div class="cover-themes__list">
                @php
                    $shelf = collect([['key' => 'booklet', 'name' => 'Booklet', 'kind' => 'Booklet’s own', 'pic' => null, 'code' => false, 'first' => \App\Support\Look::PRESETS['notebook'], 'papers' => \App\Support\Look::PAPERS]])
                        ->merge($themes->map(fn ($t) => ['key' => $t->key, 'name' => $t->name(), 'kind' => ! $t->fits() ? 'Needs Booklet '.$t->needs() : ($t->hasCode() ? 'With templates' : 'Cover pack'), 'pic' => $t->previewUrl(), 'code' => $t->hasCode(), 'first' => collect($t->presets())->first(), 'papers' => \App\Support\Look::papers($t)]));
                @endphp
                @foreach ($shelf as $t)
                    @php [, $tp, , $tr] = $t['papers'][$t['first']['paper']] ?? \App\Support\Look::PAPERS['warm']; @endphp
                    <button type="button" wire:key="theme-{{ $t['key'] }}" @class(['cover-theme', 'is-on' => $look['theme'] === $t['key']]) wire:click="wear('{{ $t['key'] }}')" aria-pressed="{{ $look['theme'] === $t['key'] ? 'true' : 'false' }}">
                        <span class="cover-theme__pic" style="--c-paper: {{ $tp }}; --c-rule: {{ $tr }}; --c-accent: {{ $t['first']['accent'] }}">
                            @if ($t['pic'])
                                <img src="{{ $t['pic'] }}" alt="" loading="lazy">
                            @else
                                <span class="cover-theme__mark"></span><i></i><i></i>
                            @endif
                        </span>
                        <span class="cover-theme__name">{{ $t['name'] }}</span>
                        <span class="cover-theme__kind">{{ $t['kind'] }}</span>
                        @if ($wearing === $t['key']) <span class="cover-theme__worn">{{ __('On the site') }}</span> @endif
                        @if (isset($updates[$t['key']])) <span class="cover-theme__update">{{ __('Update') }}</span> @endif
                    </button>
                @endforeach
            </div>

            @if ($theme)
                <div class="cover-theme-about">
                    @if ($theme->about()) <p style="margin: 0">{{ $theme->about() }}</p> @endif
                    <p class="field__hint" style="margin: 0">
                        Version {{ $theme->version() }}@if ($theme->author()) · by {{ $theme->author() }}@endif
                        @if ($theme->homepage()) · <a href="{{ $theme->homepage() }}" target="_blank" rel="noopener">{{ __('its page') }} <span aria-hidden="true">↗</span></a>@endif
                        · {{ $theme->hasCode() ? 'with its own templates' : 'a cover pack' }}
                        @if ($theme->hasCode() && config('booklet.safe_mode')) · <b>{{ __('safe mode: worn as a cover pack') }}</b>@endif
                    </p>
                    @if ($error)
                        <p class="vc-note vc-note--warn" style="margin: 0">
                            {{ $error['file'] ? __('Its template (:file) broke on', ['file' => $error['file']]) : __('Its template broke on') }} <code>{{ $error['where'] }}</code> {{ \Illuminate\Support\Carbon::parse($error['at'])->diffForHumans() }}{{ $error['count'] > 1 ? ', '.$error['count'].' times so far' : '' }}: {{ \Illuminate\Support\Str::limit($error['message'], 220) }}
                            Readers were shown Booklet’s own page instead. An update from whoever made it should fix it.
                        </p>
                    @endif
                    @foreach ($theme->problems as $problem) <p class="field__hint" style="margin: 0; color: var(--danger)">{{ $problem }}</p> @endforeach
                    @if (! $theme->fits()) <p class="vc-note vc-note--warn" style="margin: 0">It needs Booklet {{ $theme->needs() }} or newer, so the site can’t wear it.</p> @endif
                    <div class="cover-theme-about__actions">
                        @if ($origin)
                            <button type="button" @class(['btn btn--sm', 'btn--ink' => isset($updates[$theme->key])]) wire:click="lookForUpdate('{{ $theme->key }}')" wire:loading.attr="disabled" wire:target="lookForUpdate">
                                {{ isset($updates[$theme->key]) ? 'Look at version '.$updates[$theme->key] : __('Look for an update') }}
                            </button>
                        @endif
                        @if ($previous) <button type="button" class="btn btn--quiet btn--sm" wire:click="undoUpdate('{{ $theme->key }}')" wire:confirm="Put back version {{ $previous }}?">{{ __('Back to :previous', ['previous' => $previous]) }}</button> @endif
                        @if ($wearing !== $theme->key)
                            <button type="button" class="btn btn--quiet btn--sm" wire:click="remove('{{ $theme->key }}')" wire:confirm="Take {{ $theme->name() }} off the shelf? Its files are deleted.">{{ __('Take off the shelf') }}</button>
                        @endif
                    </div>
                    @include('livewire.admin.partials.share-guide', ['kind' => 'theme', 'key' => $theme->key, 'name' => $theme->name(), 'version' => $theme->version(), 'about' => $theme->about(), 'code' => $theme->hasCode(), 'from' => $origin])
                </div>
            @endif
        </section>

        <fieldset class="cover-presets">
            <legend class="field__label">{{ __('Start from') }}</legend>
            @foreach ($presets as $key => $p)
                @php [, $paper, , $rule] = $papers[$p['paper']] ?? $papers['warm']; @endphp
                <button type="button" @class(['cover-preset', 'is-on' => $look['preset'] === $key]) wire:click="preset('{{ $key }}')" aria-pressed="{{ $look['preset'] === $key ? 'true' : 'false' }}"
                        style="--c-paper: {{ $paper }}; --c-rule: {{ $rule }}; --c-accent: {{ $p['accent'] }}; --c-title: {!! $fonts[$p['title_font']][2] ?? $fonts['newsreader'][2] !!}; --c-label: {!! $fonts[$p['label_font']][2] ?? $fonts['instrument'][2] !!}">
                    <span @class(['cover-preset__book', 'is-sharp' => $p['corners'] === 'sharp'])>
                        <span class="cover-preset__mark @if ($p['tilt']) is-tilted @endif"></span>
                        <span class="cover-preset__title">{{ __('Aa') }}</span>
                        <span class="cover-preset__lines"><i></i><i></i></span>
                    </span>
                    <span class="cover-preset__name">{{ $p['name'] }}</span>
                    @if ($p['about']) <span class="cover-preset__about">{{ __($p['about']) }}</span> @endif
                </button>
            @endforeach
        </fieldset>

        <div class="cover-controls">
            <div class="cover-row">
                <div class="field">
                    <label for="cover-title-font">{{ __('Titles and reading') }}</label>
                    <select id="cover-title-font" class="input" wire:model.live="look.title_font" style="font-family: {!! $fonts[$look['title_font']][2] !!}">
                        @foreach ($titleFonts as $f) <option value="{{ $f }}" style="font-family: {!! $fonts[$f][2] !!}">{{ $fonts[$f][0] }}</option> @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="cover-label-font">{{ __('Menus and labels') }}</label>
                    <select id="cover-label-font" class="input" wire:model.live="look.label_font" style="font-family: {!! $fonts[$look['label_font']][2] !!}">
                        @foreach ($labelFonts as $f) <option value="{{ $f }}" style="font-family: {!! $fonts[$f][2] !!}">{{ $fonts[$f][0] }}</option> @endforeach
                    </select>
                </div>
            </div>
            <p class="cover-sample" style="font-family: {!! $fonts[$look['title_font']][2] !!}">{{ __('Kuih lapis, nine layers') }} <span style="font-family: {!! $fonts[$look['label_font']][2] !!}">{{ __('· 18 October · 4 min read') }}</span></p>

            <fieldset class="cover-field">
                <legend class="field__label">{{ __('Accent') }}</legend>
                <div class="cover-swatches" role="radiogroup" aria-label="{{ __('Accent colour') }}">
                    @foreach ($swatches as $hex)
                        <button type="button" role="radio" class="cover-swatch" style="--swatch: {{ $hex }}" aria-checked="{{ strtoupper($look['accent']) === strtoupper($hex) ? 'true' : 'false' }}" aria-label="{{ $hex }}" wire:click="accent('{{ $hex }}')"></button>
                    @endforeach
                    <label class="cover-swatch cover-swatch--any" title="{{ __('Any colour') }}">
                        <span class="sr-only">{{ __('Any colour') }}</span>
                        <input type="color" value="{{ strtolower($look['accent']) }}" wire:model.live.debounce.300ms="look.accent">
                    </label>
                    <code class="cover-hex">{{ $look['accent'] }}</code>
                </div>
            </fieldset>

            <fieldset class="cover-field">
                <legend class="field__label">{{ __('Paper') }}</legend>
                <div class="cover-chips">
                    @foreach ($papers as $key => [$name, $paper, , $rule])
                        <label class="cover-chip"><input type="radio" wire:model.live="look.paper" value="{{ $key }}"><span><i style="background: {{ $paper }}; border-color: {{ $rule }}"></i>{{ __($name) }}</span></label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="cover-field">
                <legend class="field__label">{{ __('Header') }}</legend>
                <div class="cover-layouts">
                    @foreach ($headers as $key => [$name, $about])
                        <label class="cover-layout"><input type="radio" wire:model.live="look.header" value="{{ $key }}">
                            <span><span class="cover-sketch cover-sketch--header-{{ $key }}" aria-hidden="true"><i></i><i></i><i></i></span><b>{{ __($name) }}</b><small>{{ __($about) }}</small></span></label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="cover-field">
                <legend class="field__label">{{ __('Home page') }}</legend>
                <div class="cover-layouts">
                    @foreach ($homes as $key => [$name, $about])
                        <label class="cover-layout"><input type="radio" wire:model.live="look.home" value="{{ $key }}">
                            <span><span class="cover-sketch cover-sketch--home-{{ $key }}" aria-hidden="true"><i></i><i></i><i></i><i></i></span><b>{{ __($name) }}</b><small>{{ __($about) }}</small></span></label>
                    @endforeach
                </div>
            </fieldset>

            <div class="cover-row">
                <fieldset class="cover-field">
                    <legend class="field__label">{{ __('Corners') }}</legend>
                    <div class="cover-chips">
                        @foreach ($corners as $key => $name)
                            <label class="cover-chip"><input type="radio" wire:model.live="look.corners" value="{{ $key }}"><span><i class="cover-corner cover-corner--{{ $key }}"></i>{{ __($name) }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="nav-switch" style="align-self: end">
                    <input type="checkbox" role="switch" wire:model.live="look.tilt">
                    <span><b>{{ __('Tilted stickers') }}</b> <span class="field__hint">{{ __('Labels set at a slight angle, like stickers.') }}</span></span>
                </label>
            </div>

            @if ($options)
                <fieldset class="cover-field cover-options">
                    <legend class="field__label">{{ $theme->name() }}’s own</legend>
                    @foreach ($options as $handle => $option)
                        @if ($option['type'] === 'toggle')
                            <label class="nav-switch" wire:key="opt-{{ $handle }}">
                                <input type="checkbox" role="switch" wire:model.live="look.options.{{ $handle }}">
                                <span><b>{{ __($option['label']) }}</b> @if ($option['hint']) <span class="field__hint">{{ __($option['hint']) }}</span> @endif</span>
                            </label>
                        @elseif ($option['type'] === 'select')
                            <div class="cover-option" wire:key="opt-{{ $handle }}">
                                <span class="cover-option__label">{{ __($option['label']) }}</span>
                                <div class="cover-chips">
                                    @foreach ($option['options'] as $value => $label)
                                        <label class="cover-chip"><input type="radio" wire:model.live="look.options.{{ $handle }}" value="{{ $value }}"><span>{{ __($label) }}</span></label>
                                    @endforeach
                                </div>
                                @if ($option['hint']) <p class="field__hint" style="margin: 0">{{ __($option['hint']) }}</p> @endif
                            </div>
                        @else
                            <label class="cover-option cover-option--color" wire:key="opt-{{ $handle }}">
                                <span class="cover-option__label">{{ __($option['label']) }}</span>
                                <span class="cover-option__color"><input type="color" value="{{ strtolower($look['options'][$handle] ?? $option['default']) }}" wire:model.live.debounce.300ms="look.options.{{ $handle }}"> <code>{{ $look['options'][$handle] ?? $option['default'] }}</code></span>
                                @if ($option['hint']) <span class="field__hint">{{ __($option['hint']) }}</span> @endif
                            </label>
                        @endif
                    @endforeach
                </fieldset>
            @endif
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the site, wearing it --}}
    <section class="page page--right page--stack" id="cover-right" style="--ribbon: {{ $look['accent'] }}">
        <div class="ribbon" aria-hidden="true"></div>

        {{-- Each of these is its own keyed block, so switching between them swaps the whole thing (the preview is wire:ignore). --}}
        @if ($waiting)
            <div wire:key="right-waiting" style="display: contents">
            {{-- A theme unpacked to one side: what it is, and a yes --}}
            <div @class(['ribbon-clear', 'cover-staged', 'cover-staged--code' => $waiting['code']])>
                <p class="eyebrow" style="margin: 0">{{ $waiting['replaces'] ? __('Update, waiting for a yes') : __('Waiting for a yes') }}</p>
                <p class="cover-staged__name">{{ $waiting['name'] }} <span class="muted">{{ $waiting['version'] }}</span></p>
                @if ($waiting['about']) <p style="margin: 0">{{ __($waiting['about']) }}</p> @endif
                <p class="field__hint" style="margin: 0">
                    {{ $waiting['code'] ? __('With its own templates') : __('A cover pack') }}@if ($waiting['author']) · by {{ $waiting['author'] }}@endif
                    ·  {{ trans_choice(':count file|:count files', $waiting['files']) }}, {{ number_format($waiting['bytes'] / 1024) }} KB
                    @if ($waiting['replaces']) · replaces {{ $waiting['replaces']['version'] }}@endif
                </p>
                <ul class="cover-staged__brings">
                    @if ($waiting['presets']) <li>{{ __('Presets: :implode', ['implode' => implode(', ', $waiting['presets'])]) }}</li> @endif
                    @if ($waiting['fonts']) <li>{{ __('Fonts: :implode', ['implode' => implode(', ', $waiting['fonts'])]) }}</li> @endif
                    @if ($waiting['options']) <li>{{ __('Its own settings: :implode', ['implode' => implode(', ', $waiting['options'])]) }}</li> @endif
                    @if ($waiting['templates']) <li>Templates: {{ implode(', ', array_map(fn ($v) => str_replace(['components.site.', 'site.', 'errors.'], ['', '', 'error '], $v), $waiting['templates'])) }}</li> @endif
                    @if ($waiting['scripts']) <li>{{ __('Scripts: :implode', ['implode' => implode(', ', $waiting['scripts'])]) }}</li> @endif
                </ul>
                @foreach ($waiting['problems'] as $problem) <p class="field__hint" style="margin: 0; color: var(--danger)">{{ $problem }}</p> @endforeach
                @if (! $waiting['fits'])
                    <p class="vc-note vc-note--warn" style="margin: 0">{{ __('It needs Booklet :needs or newer; this is :config.', ['needs' => $waiting['needs'], 'config' => config('booklet.version')]) }}</p>
                @elseif ($waitingBlocked)
                    <p class="vc-note vc-note--warn" style="margin: 0">{{ $waitingBlocked }}</p>
                @else
                    @if ($waiting['code'])
                        <p class="vc-note vc-note--warn" style="margin: 0">{{ __('This theme has templates:value: code that runs with the whole site’s keys. Only add it if you trust who made it.', ['value' => $waiting['scripts'] ? ' and scripts' : '']) }}</p>
                        @if ($this->needsPassword())
                            <div class="field" style="margin: 0">
                                <label for="theme-password">{{ __('Your password, to say yes') }}</label>
                                <input id="theme-password" type="password" class="input" wire:model="password" autocomplete="current-password" wire:keydown.enter="install">
                                @error('password') <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        @else
                            @php $confirmedAt = (int) session('auth.password_confirmed_at', 0); @endphp
                            <p class="field__hint" style="margin: 0">No password needed this time: you gave it {{ \Illuminate\Support\Carbon::createFromTimestamp($confirmedAt)->diffForHumans() }}, and Booklet trusts that for {{ (int) round(config('auth.password_timeout', 10800) / 3600) }} hours.</p>
                        @endif
                    @endif
                    @error('install') <p class="field__error">{{ $message }}</p> @enderror
                    <div style="display: flex; gap: 10px; flex-wrap: wrap">
                        <button type="button" class="btn btn--ink" wire:click="install">{{ $waiting['replaces'] ? __('Update it') : __('Add it') }}</button>
                        <button type="button" class="btn btn--quiet" wire:click="cancelStaged">{{ __('Not now') }}</button>
                    </div>
                @endif
                @if (! $waiting['fits'] || $waitingBlocked)
                    <button type="button" class="btn btn--quiet btn--sm" style="align-self: flex-start" wire:click="cancelStaged">{{ __('Put it away') }}</button>
                @endif
            </div>
            </div>
        @elseif ($adding)
            <div wire:key="right-adding" style="display: contents">
            {{-- Adding a theme: what's on GitHub, or one you were given, where the preview usually is --}}
            <div class="ribbon-clear cover-find__head">
                <div>
                    <h2 class="heading heading--sub">{{ __('Add a theme') }}</h2>
                    <p class="field__hint" style="margin: 6px 0 0">{{ __('Nothing changes until you’ve looked inside and said yes. Then it shows in the preview, and the site only changes when you press') }} <b>{{ __('Use this cover') }}</b>.</p>
                </div>
                <button type="button" class="btn btn--quiet btn--sm" wire:click="stopAdding">{{ __('Back to the preview') }}</button>
            </div>
            @if ($blocked)
                <p class="vc-note vc-note--warn" style="margin: 0">{{ $blocked }}</p>
            @else
                @include('livewire.admin.partials.catalog', ['kind' => 'theme'])
                <div class="cover-add">
                    <p class="cover-add__or" style="margin: 0; padding: 0; border: 0">{{ __('Or, if you were given one') }}</p>
                    <form wire:submit="fetch" class="field" style="margin: 0">
                        <label for="theme-source">{{ __('From a link or GitHub') }}</label>
                        <p class="field__hint" style="margin: 0 0 6px">{{ __('Paste what you were given: a name like') }} <code>{{ __('someone/booklet-harbour') }}</code>{{ __(', its github.com address, or a link to a .zip.') }}</p>
                        <div class="cover-add__row">
                            <input id="theme-source" type="text" class="input" wire:model="source" placeholder="{{ __('someone/booklet-harbour') }}" spellcheck="false">
                            <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="fetch">
                                <span wire:loading.remove wire:target="fetch">{{ __('Look inside') }}</span>
                                <span wire:loading wire:target="fetch">{{ __('Fetching…') }}</span>
                            </button>
                        </div>
                        @error('source') <p class="field__error">{{ $message }}</p> @enderror
                    </form>
                    <form method="POST" action="{{ route('admin.settings.themes.upload') }}" enctype="multipart/form-data" class="field" style="margin: 0">
                        @csrf
                        <label for="theme-file">{{ __('From a .zip on your computer') }}</label>
                        <div class="cover-add__row">
                            <input id="theme-file" type="file" name="theme" accept=".zip,application/zip" required>
                            <button type="submit" class="btn btn--sm">{{ __('Upload and look inside') }}</button>
                        </div>
                        @error('upload') <p class="field__error">{{ $message }}</p> @enderror
                        @error('theme') <p class="field__error">{{ $message }}</p> @enderror
                    </form>
                </div>
            @endif
            <p class="field__hint" style="margin: 0"><b>{{ __('Cover packs') }}</b> {{ __('bring fonts, colours, presets and styles, and are safe to add from anyone: Booklet reads them through first.') }} <b>{{ __('Themes with templates') }}</b> {{ __('change the pages themselves; they’re code, so only add ones from people you trust.') }}</p>
            </div>
        @else
        <div wire:key="right-preview" style="display: contents">
        <div class="menu-live" style="margin-top: 0; padding-top: 0; border-top: 0" wire:ignore
             x-data="{
                wide: true,
                src: @js($previewUrl),
                stamp: Date.now(),
                get url() { return this.src + '&v=' + this.stamp; },
                noBar(frame) {
                    try {
                        const style = frame.contentDocument.createElement('style');
                        style.textContent = 'html { scrollbar-width: none } html::-webkit-scrollbar { display: none }';
                        frame.contentDocument.head.appendChild(style);
                    } catch (e) {}
                },
                fit() { const box = this.$refs.box; if (! box) return; const room = this.$el.clientWidth - 2; box.style.setProperty('--fit', this.wide ? Math.min(1, room / 1280) : Math.min(.8, room / 390)); },
             }"
             x-init="fit(); new ResizeObserver(() => fit()).observe($el)"
             x-on:look-preview.window="stamp = Date.now()">
            <div class="menu-live__head ribbon-clear">
                <h2 class="heading heading--sub">{{ __('Live preview') }}</h2>
                <div class="filters" role="group" aria-label="{{ __('Preview width') }}">
                    <button type="button" class="pill" x-on:click="wide = true; $nextTick(() => fit())" :aria-pressed="wide.toString()">{{ __('Wide') }}</button>
                    <button type="button" class="pill" x-on:click="wide = false; $nextTick(() => fit())" :aria-pressed="(! wide).toString()">{{ __('Phone') }}</button>
                </div>
            </div>
            <p class="field__hint" style="margin: 6px 0 0">{{ __('Your home page as readers will see it. Only you see this until you use it.') }}</p>
            <div class="menu-live__box cover-preview" x-ref="box" :class="{ 'menu-live__box--phone': ! wide }">
                <iframe :src="url" x-on:load="noBar($event.target)" :title="'Preview of the site at ' + (wide ? 'full' : 'phone') + ' width'"></iframe>
            </div>
        </div>
        </div>
        @endif

        @unless ($adding || $waiting)
        <div class="cover-actions">
            <button type="button" class="btn btn--ink btn--lg" wire:click="save" @disabled(! $dirty)>{{ __('Use this cover') }}</button>
            @if ($dirty)
                <button type="button" class="btn btn--quiet" wire:click="undo">{{ __('Back to the one in use') }}</button>
                <span class="saved-note">{{ __('Not on the site yet') }}</span>
            @endif
        </div>
        @endunless

        <x-admin.folio side="right" />
    </section>
</div>
