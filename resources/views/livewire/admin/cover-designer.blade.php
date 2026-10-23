<div style="display: contents">
    <link rel="stylesheet" href="{{ $allFonts }}">

    {{-- Left page: presets, then each choice --}}
    <section class="page page--left page--stack" style="flex-basis: 420px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Settings</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">The cover</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">How the site looks to readers. Start from a cover, then change anything. The preview shows it at once; the site changes only when you press <b>Use this cover</b>.</p>
        </div>

        <fieldset class="cover-presets">
            <legend class="field__label">Start from</legend>
            @foreach ($presets as $key => $p)
                @php [, $paper, , $rule] = $papers[$p['paper']]; @endphp
                <button type="button" @class(['cover-preset', 'is-on' => $look['preset'] === $key]) wire:click="preset('{{ $key }}')" aria-pressed="{{ $look['preset'] === $key ? 'true' : 'false' }}"
                        style="--c-paper: {{ $paper }}; --c-rule: {{ $rule }}; --c-accent: {{ $p['accent'] }}; --c-title: {!! $fonts[$p['title_font']][2] !!}; --c-label: {!! $fonts[$p['label_font']][2] !!}">
                    <span @class(['cover-preset__book', 'is-sharp' => $p['corners'] === 'sharp'])>
                        <span class="cover-preset__mark @if ($p['tilt']) is-tilted @endif"></span>
                        <span class="cover-preset__title">Aa</span>
                        <span class="cover-preset__lines"><i></i><i></i></span>
                    </span>
                    <span class="cover-preset__name">{{ $p['name'] }}</span>
                    <span class="cover-preset__about">{{ $p['about'] }}</span>
                </button>
            @endforeach
        </fieldset>

        <div class="cover-controls">
            <div class="cover-row">
                <div class="field">
                    <label for="cover-title-font">Titles and reading</label>
                    <select id="cover-title-font" class="input" wire:model.live="look.title_font" style="font-family: {!! $fonts[$look['title_font']][2] !!}">
                        @foreach ($titleFonts as $f) <option value="{{ $f }}" style="font-family: {!! $fonts[$f][2] !!}">{{ $fonts[$f][0] }}</option> @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="cover-label-font">Menus and labels</label>
                    <select id="cover-label-font" class="input" wire:model.live="look.label_font" style="font-family: {!! $fonts[$look['label_font']][2] !!}">
                        @foreach ($labelFonts as $f) <option value="{{ $f }}" style="font-family: {!! $fonts[$f][2] !!}">{{ $fonts[$f][0] }}</option> @endforeach
                    </select>
                </div>
            </div>
            <p class="cover-sample" style="font-family: {!! $fonts[$look['title_font']][2] !!}">Kuih lapis, nine layers <span style="font-family: {!! $fonts[$look['label_font']][2] !!}">· 18 October · 4 min read</span></p>

            <fieldset class="cover-field">
                <legend class="field__label">Accent</legend>
                <div class="cover-swatches" role="radiogroup" aria-label="Accent colour">
                    @foreach ($swatches as $hex)
                        <button type="button" role="radio" class="cover-swatch" style="--swatch: {{ $hex }}" aria-checked="{{ strtoupper($look['accent']) === strtoupper($hex) ? 'true' : 'false' }}" aria-label="{{ $hex }}" wire:click="accent('{{ $hex }}')"></button>
                    @endforeach
                    <label class="cover-swatch cover-swatch--any" title="Any colour">
                        <span class="sr-only">Any colour</span>
                        <input type="color" value="{{ strtolower($look['accent']) }}" wire:model.live.debounce.300ms="look.accent">
                    </label>
                    <code class="cover-hex">{{ $look['accent'] }}</code>
                </div>
            </fieldset>

            <fieldset class="cover-field">
                <legend class="field__label">Paper</legend>
                <div class="cover-chips">
                    @foreach ($papers as $key => [$name, $paper, , $rule])
                        <label class="cover-chip"><input type="radio" wire:model.live="look.paper" value="{{ $key }}"><span><i style="background: {{ $paper }}; border-color: {{ $rule }}"></i>{{ $name }}</span></label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="cover-field">
                <legend class="field__label">Header</legend>
                <div class="cover-layouts">
                    @foreach ($headers as $key => [$name, $about])
                        <label class="cover-layout"><input type="radio" wire:model.live="look.header" value="{{ $key }}">
                            <span><span class="cover-sketch cover-sketch--header-{{ $key }}" aria-hidden="true"><i></i><i></i><i></i></span><b>{{ $name }}</b><small>{{ $about }}</small></span></label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="cover-field">
                <legend class="field__label">Home page</legend>
                <div class="cover-layouts">
                    @foreach ($homes as $key => [$name, $about])
                        <label class="cover-layout"><input type="radio" wire:model.live="look.home" value="{{ $key }}">
                            <span><span class="cover-sketch cover-sketch--home-{{ $key }}" aria-hidden="true"><i></i><i></i><i></i><i></i></span><b>{{ $name }}</b><small>{{ $about }}</small></span></label>
                    @endforeach
                </div>
            </fieldset>

            <div class="cover-row">
                <fieldset class="cover-field">
                    <legend class="field__label">Corners</legend>
                    <div class="cover-chips">
                        @foreach ($corners as $key => $name)
                            <label class="cover-chip"><input type="radio" wire:model.live="look.corners" value="{{ $key }}"><span><i class="cover-corner cover-corner--{{ $key }}"></i>{{ $name }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="nav-switch" style="align-self: end">
                    <input type="checkbox" role="switch" wire:model.live="look.tilt">
                    <span><b>Tilted stickers</b> <span class="field__hint">Labels set at a slight angle, like stickers.</span></span>
                </label>
            </div>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the site, wearing it --}}
    <section class="page page--right page--stack" style="--ribbon: {{ $look['accent'] }}">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="menu-live" style="margin-top: 0; padding-top: 0; border-top: 0" wire:ignore
             x-data="{
                wide: true,
                src: @js($previewUrl),
                stamp: 0,
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
                <h2 class="heading heading--sub">Live preview</h2>
                <div class="filters" role="group" aria-label="Preview width">
                    <button type="button" class="pill" x-on:click="wide = true; $nextTick(() => fit())" :aria-pressed="wide.toString()">Wide</button>
                    <button type="button" class="pill" x-on:click="wide = false; $nextTick(() => fit())" :aria-pressed="(! wide).toString()">Phone</button>
                </div>
            </div>
            <p class="field__hint" style="margin: 6px 0 0">Your home page as readers will see it. Only you see this until you use it.</p>
            <div class="menu-live__box cover-preview" x-ref="box" :class="{ 'menu-live__box--phone': ! wide }">
                <iframe :src="url" x-on:load="noBar($event.target)" :title="'Preview of the site at ' + (wide ? 'full' : 'phone') + ' width'"></iframe>
            </div>
        </div>

        <div class="cover-actions">
            <button type="button" class="btn btn--ink btn--lg" wire:click="save" @disabled(! $dirty)>Use this cover</button>
            @if ($dirty)
                <button type="button" class="btn btn--quiet" wire:click="undo">Back to the one in use</button>
                <span class="saved-note">Not on the site yet</span>
            @endif
        </div>

        <x-admin.folio side="right" />
    </section>
</div>
