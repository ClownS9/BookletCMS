<main class="setup">
    <div class="book setup__book">
        <div class="book__pages">
            {{-- Left page: the steps --}}
            <section class="page page--left" style="flex-basis: 360px">
                <div style="display: flex; align-items: center; gap: 10px">
                    <x-admin.logo />
                    <span class="serif" style="font-size: 22px; font-weight: 500">{{ __('booklet') }}</span>
                </div>
                <h1 class="display" style="margin-top: 40px">{{ __('Start your book') }}</h1>
                <p class="lede" style="margin-top: 12px">{{ __('Four short steps. You can change all of it later in Settings.') }}</p>

                <ol class="setup__steps">
                    @foreach ($steps as $i => $label)
                        <li @class(['is-current' => $i === $step, 'is-done' => $i < $step]) @if ($i === $step) aria-current="step" @endif>
                            <span class="setup__n">{{ $i < $step ? '✓' : $i + 1 }}</span>
                            <span>{{ __($label) }}</span>
                        </li>
                    @endforeach
                </ol>

                <p class="page__folio">{{ __('Preface') }}</p>
            </section>

            {{-- Right page: the step itself --}}
            <section class="page page--right" style="--ribbon: {{ $accents[$accent] }}">
                <div class="ribbon" aria-hidden="true"></div>
                <p class="eyebrow">{{ __('Step :value of 4', ['value' => $step + 1]) }}</p>

                <form wire:submit="next" class="setup__form" wire:key="step-{{ $step }}">
                    @if ($step === 0)
                        <h2 class="heading">{{ __('Name your book') }}</h2>
                        <div class="field">
                            <label for="site">{{ __('Site name') }}</label>
                            <input id="site" type="text" wire:model="siteName" autofocus>
                            @error('siteName') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label for="tag">{{ __('Tagline') }} <span class="muted" style="font-weight: 400">{{ __('(optional)') }}</span></label>
                            <input id="tag" type="text" wire:model="tagline" placeholder="{{ __('A line about what you write') }}">
                            @error('tagline') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($step === 1)
                        <h2 class="heading">{{ __('Write your name inside') }}</h2>
                        <div class="field">
                            <label for="nm">{{ __('Your name') }}</label>
                            <input id="nm" type="text" wire:model="name" autocomplete="name" autofocus>
                            @error('name') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label for="em">{{ __('Email') }}</label>
                            <input id="em" type="email" wire:model="email" autocomplete="email">
                            @error('email') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <x-admin.password id="pw" model="password" label="{{ __('Password') }}" autocomplete="new-password" meter
                                          :personal="[$siteName]" :personal-from="['nm', 'em']"
                                          hint="At least 10 characters. A few unrelated words are easy to remember and hard to guess." />
                    @elseif ($step === 2)
                        <h2 class="heading">{{ __('Pick your sticker') }}</h2>
                        <p style="color: var(--muted)">{{ __('The accent colour for buttons, ribbons and your site’s highlights.') }}</p>
                        <div class="swatches" role="radiogroup" aria-label="{{ __('Accent') }}">
                            @foreach ($accents as $label => $hex)
                                <button type="button" role="radio" class="swatch" aria-checked="{{ $accent === $label ? 'true' : 'false' }}" aria-label="{{ __($label) }}"
                                        wire:click="pickAccent('{{ __($label) }}')" style="--swatch: {{ $hex }}; --tilt: {{ $loop->index % 2 ? '5deg' : '-5deg' }}"></button>
                            @endforeach
                        </div>
                        <p class="serif" style="font-size: 20px">{{ $accent }}</p>
                        @error('accent') <p class="field__error">{{ $message }}</p> @enderror
                    @else
                        <h2 class="heading">{{ __('Your book is ready') }}</h2>
                        <p class="lede">{{ __('We’ve added a Home page and a first draft so the pages aren’t blank. Stacy is on hand if you get stuck.') }}</p>
                        <span class="sticker sticker--mint" style="align-self: flex-start; font-size: 15px; padding: 8px 14px">{{ __('All set') }}</span>
                    @endif

                    <div class="setup__nav">
                        @if ($step > 0 && $step < 3)
                            <button type="button" class="btn btn--quiet" wire:click="back">{{ __('← Back') }}</button>
                        @else
                            <span></span>
                        @endif

                        @if ($step < 3)
                            <button type="submit" class="btn btn--ink">
                                <span wire:loading.remove wire:target="next">{{ $step === 2 ? __('Bind my book') : __('Continue') }}</span>
                                <span wire:loading wire:target="next">{{ $step === 2 ? __('Binding…') : __('One moment…') }}</span>
                            </button>
                        @else
                            {{-- A full page load, so the new session is used from here on. --}}
                            <a href="{{ route('admin.contents') }}" class="btn btn--ink">{{ __('Open my book') }}</a>
                        @endif
                    </div>
                </form>
            </section>
        </div>
    </div>

</main>
