<div style="display: contents">
    {{-- Just the letters "Aa" of each font, for the font cards --}}
    @foreach ($fontLinks as $link)
        <link rel="stylesheet" href="{{ $link }}">
    @endforeach

    {{-- Left page: the choices --}}
    <section class="page page--left page--stack yours" style="flex-basis: 520px">
        <div>
            <a href="{{ route('admin.account') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Your account') }}</a>
            <p class="eyebrow chapter-mark">{{ __('Ex libris · just for you') }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Make it yours') }}</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('How Booklet looks and opens for you. Everyone else keeps their own.') }}</p>
        </div>

        <fieldset class="yours__set">
            <legend>{{ __('How it opens') }}</legend>
            <div class="yours__layouts" role="radiogroup" aria-label="{{ __('Layout') }}">
                @foreach ($layouts as $key => [$name, $about])
                    <button type="button" role="radio" aria-checked="{{ $yours['layout'] === $key ? 'true' : 'false' }}" wire:click="choose('layout', '{{ $key }}')" class="yours__layout">
                        <span class="yours__thumb yours__thumb--{{ $key }}" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span class="yours__name"><span class="yours__radio" aria-hidden="true"></span>{{ __($name) }}</span>
                        <small>{{ __($about) }}</small>
                    </button>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="yours__set">
            <legend>{{ __('Theme') }}</legend>
            <div class="yours__themes" role="radiogroup" aria-label="{{ __('Theme') }}">
                @foreach ($themes as $key => [$name, $cover, $paper, $ink, $faint])
                    <button type="button" role="radio" aria-checked="{{ $yours['theme'] === $key ? 'true' : 'false' }}" wire:click="choose('theme', '{{ $key }}')" class="yours__theme">
                        <span class="yours__swatch" style="--c: {{ $cover }}; --p: {{ $paper }}; --i: {{ $ink }}; --f: {{ $faint }}" aria-hidden="true"><i></i><i></i></span>
                        <span>{{ __($name) }}</span>
                    </button>
                @endforeach
            </div>
            <p class="field__hint">{{ __('Stickers keep their colours in every theme, so chapters still look like themselves.') }}</p>
        </fieldset>

        <fieldset class="yours__set">
            <legend>{{ __('Fonts') }}</legend>
            <div class="yours__fonts" role="radiogroup" aria-label="{{ __('Fonts') }}">
                @foreach ($fonts as $key => [$name, $about, $head])
                    <button type="button" role="radio" aria-checked="{{ $yours['font'] === $key ? 'true' : 'false' }}" wire:click="choose('font', '{{ $key }}')" class="yours__font">
                        <span class="yours__aa" style="font-family: {{ $head }}" aria-hidden="true">Aa</span>
                        <span class="yours__fontname">{{ __($name) }}</span>
                        <small>{{ __($about) }}</small>
                    </button>
                @endforeach
            </div>
            <p class="field__hint">{{ __('Only the admin changes. Your site keeps its own fonts.') }}</p>
        </fieldset>

        <div class="yours__small">
            <div>
                <p class="field__label" id="yours-space">{{ __('Spacing') }}</p>
                <div class="segmented" role="radiogroup" aria-labelledby="yours-space">
                    @foreach ($densities as $key => $name)
                        <button type="button" role="radio" aria-checked="{{ $yours['density'] === $key ? 'true' : 'false' }}" wire:click="choose('density', '{{ $key }}')">{{ __($name) }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="field__label" id="yours-size">{{ __('Text size') }}</p>
                <div class="segmented" role="radiogroup" aria-labelledby="yours-size">
                    @foreach ($sizes as $key => $name)
                        <button type="button" role="radio" aria-checked="{{ $yours['size'] === $key ? 'true' : 'false' }}" aria-label="{{ __($name) }}" wire:click="choose('size', '{{ $key }}')" class="yours__a yours__a--{{ $key }}">A</button>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="field__label" id="yours-night">{{ __('Night reading') }}</p>
                <div class="segmented" role="radiogroup" aria-labelledby="yours-night">
                    @foreach (['off' => __('Off'), 'on' => __('On'), 'device' => __('With my device')] as $key => $name)
                        <button type="button" role="radio" aria-checked="{{ $night === $key ? 'true' : 'false' }}" wire:click="night('{{ $key }}')">{{ $name }}</button>
                    @endforeach
                </div>
            </div>
            <label class="switch-label">
                <button type="button" class="switch" role="switch" aria-checked="{{ $yours['turn'] ? 'true' : 'false' }}" wire:click="toggleTurn" aria-describedby="yours-turn-hint"></button>
                <span><span>{{ __('Pages turn') }}</span><small id="yours-turn-hint">{{ __('The little flip between chapters, in the Book layout') }}</small></span>
            </label>
        </div>

        <div class="yours__foot">
            <button type="button" class="btn btn--quiet" wire:click="putBack">{{ __('Put back Booklet’s own look') }}</button>
            <span class="field__hint" style="margin-left: auto">{{ __('Kept as you choose. Only you see this.') }}</span>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: how it looks, and your menu --}}
    <section class="page page--right page--stack" style="--ribbon: var(--lilac)">
        <div class="ribbon" aria-hidden="true"></div>
        <p class="eyebrow ribbon-clear">{{ __('How it looks') }}</p>

        <div class="yours__preview yours__preview--{{ $yours['layout'] }}" role="img" aria-label="{{ $yours['layout'] === 'book' ? __('A small picture of the Book layout') : __('A small picture of the Dashboard layout') }}">
            @if ($yours['layout'] === 'book')
                <span class="yours__p-flags"><i></i><i></i><i></i><i></i></span>
                <span class="yours__p-book"><span><i></i><i></i><b></b><b></b><em></em></span><span><i></i><u></u><u></u><u></u><u></u></span></span>
                <span class="yours__p-tabs"><i></i><i></i><i></i></span>
            @else
                <span class="yours__p-side"><i></i><i></i><i></i><i></i><i></i></span>
                <span class="yours__p-main"><i></i><span><b></b><b></b><b></b><b></b></span><span><em></em><em></em></span></span>
            @endif
        </div>

        <div class="yours__summary">
            <div>
                <p>{{ __($layouts[$yours['layout']][0]) }} · {{ __($themes[$yours['theme']][0]) }} · {{ __($fonts[$yours['font']][0]) }}</p>
                <small>{{ $yours['layout'] === 'book' ? __('Stickers, the thumb index and page turning, as Booklet comes.') : __('Everything in a sidebar on the left. Switch back any time.') }}</small>
            </div>
        </div>

        <a href="{{ route('admin.account.menu') }}" class="yours__menu-link" wire:navigate>
            <span class="yours__mini-flags" aria-hidden="true"><i style="background: var(--coral)"></i><i style="background: var(--sun)"></i><i style="background: var(--mint)"></i></span>
            <span><strong>{{ __('Your menu') }}</strong><small>{{ __('Move, hide, rename and recolour the chapters and tools') }}</small></span>
            <span aria-hidden="true">→</span>
        </a>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
