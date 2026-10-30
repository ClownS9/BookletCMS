<div style="display: contents">
    {{-- Left page: profile --}}
    <form wire:submit="saveProfile" class="page page--left page--stack" style="flex-basis: 440px">
        <div>
            <p class="eyebrow">{{ __('Ex libris') }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Your account') }}</h1>
        </div>

        <div class="bookplate" style="gap: 20px; padding: 18px; flex-wrap: wrap">
            @if ($avatarUpload)
                <span class="avatar" style="--size: 96px"><img src="{{ $avatarUpload->temporaryUrl() }}" alt=""></span>
            @else
                <x-admin.avatar :user="$user" size="96" presence />
            @endif
            <span style="display: flex; flex-direction: column; gap: 6px">
                <span style="font-size: 15px; font-weight: 500">{{ __('Profile picture') }}</span>
                <span class="field__hint">{{ __('Shown on your entries and the bookplate. Up to 4 MB.') }}</span>
                <span style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px">
                    <label class="btn btn--sm" style="position: relative; overflow: hidden">
                        <span wire:loading.remove wire:target="avatarUpload">{{ __('Change') }}</span>
                        <span wire:loading wire:target="avatarUpload">{{ __('Uploading…') }}</span>
                        <input type="file" accept="image/*" wire:model="avatarUpload" style="position: absolute; inset: 0; opacity: 0; cursor: pointer">
                    </label>
                    @if ($user->avatar)
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="removeAvatar">{{ __('Remove') }}</button>
                    @endif
                </span>
            </span>
        </div>
        @error('avatarUpload') <p class="field__error">{{ $message }}</p> @enderror

        <div class="two-up">
            <div class="field">
                <label for="name">{{ __('Name') }}</label>
                <input id="name" type="text" wire:model="name" autocomplete="name">
                @error('name') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="byline">{{ __('Byline') }}</label>
                <input id="byline" type="text" wire:model="byline" placeholder="{{ __('How your name appears on posts') }}">
                @error('byline') <p class="field__error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div x-data="{ was: @js(mb_strtolower((string) $user->email)), now: @js(mb_strtolower((string) $user->email)) }" style="display: flex; flex-direction: column; gap: 14px">
            <div class="field">
                <label for="email">{{ __('Email') }}</label>
                <input id="email" type="email" wire:model="email" autocomplete="email" maxlength="190" x-on:input="now = $event.target.value.trim().toLowerCase()">
                @error('email') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            {{-- The email you sign in with only changes with your password --}}
            <div x-show="now !== was" x-cloak>
                <x-admin.password id="email-password" model="emailPassword" label="{{ __('Your password, to change your sign-in email') }}" autocomplete="current-password"
                                  hint="{{ __('We’ll also send a note to your old address, in case it wasn’t you.') }}" />
            </div>
            @error('emailPassword')
                <span x-init="now = '__changed__'"></span>
            @enderror
        </div>

        <div class="field">
            <label for="bio">{{ __('Short bio') }}</label>
            <textarea id="bio" rows="3" wire:model="bio" placeholder="{{ __('A line or two for your author card') }}"></textarea>
            @error('bio') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="book-language">{{ __('The book’s language') }}</label>
            <select id="book-language" class="input" wire:model.live="language" style="max-width: 320px">
                <option value="">{{ __('Same as this browser (:language)', ['language' => \App\Support\BookLanguage::LANGUAGES[\App\Support\BookLanguage::fromBrowser(request())][1]]) }}</option>
                @foreach (\App\Support\BookLanguage::LANGUAGES as $code => [$english, $own])
                    <option value="{{ $code }}">{{ $own }}{{ $own !== $english ? ' ('.$english.')' : '' }}</option>
                @endforeach
            </select>
            <p class="field__hint">{{ __('Only for you, in the book. Readers see the site in its own language. A few pages are still in English.') }}</p>
        </div>

        <h2 class="heading" style="margin-top: 8px">{{ __('Notes to you') }}</h2>
        <div class="setting-rows">
            @foreach ($options as $key => [$label, $hint])
                <div class="setting-row">
                    <span>
                        <span id="pref-{{ $key }}" style="display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 500">
                            @if ($key === 'night') <x-admin.icon name="moon" size="15" /> @endif {{ __($label) }}
                        </span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ __($hint) }}</span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-labelledby="pref-{{ $key }}"
                            aria-checked="{{ ($preferences[$key] ?? false) ? 'true' : 'false' }}"
                            wire:click="togglePreference('{{ $key }}')"></button>
                </div>
            @endforeach
        </div>
        @if (\App\Support\MailSettings::status()['state'] === 'off')
            <p class="field__hint" style="margin-top: -6px">{{ __('Emails aren’t being sent yet: they’re only written to the log.') }}
                @if ($user->isAdmin()) <a href="{{ route('admin.settings.email') }}" wire:navigate>{{ __('Set up email →') }}</a> @else {{ __('Ask an admin to set up email in Settings.') }} @endif</p>
        @endif

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
            <button type="submit" class="btn btn--ink btn--lg">
                <span wire:loading.remove wire:target="saveProfile">{{ __('Save changes') }}</span>
                <span wire:loading wire:target="saveProfile">{{ __('Saving…') }}</span>
            </button>
        </div>

        <x-admin.folio side="left" style="padding-top: 8px" />
    </form>

    {{-- Right page: password and signing out --}}
    <section class="page page--right page--stack" style="--ribbon: var(--lilac)">
        <div class="ribbon" aria-hidden="true"></div>

        <form wire:submit="savePassword" style="display: flex; flex-direction: column; gap: 16px; max-width: 520px">
            <h2 class="heading">{{ __('Password') }}</h2>
            <x-admin.password id="current-password" model="currentPassword" label="{{ __('Current password') }}" autocomplete="current-password" />
            <div class="two-up">
                <x-admin.password id="new-password" model="newPassword" label="{{ __('New password') }}" autocomplete="new-password" meter
                                  :personal="[$user->name, $user->email, $user->byline, app(\App\Booklet\Site::class)->name()]" />
                <x-admin.password id="new-password-confirmation" model="newPassword_confirmation" label="{{ __('Type it again') }}" autocomplete="new-password" match="new-password" />
            </div>
            <p class="field__hint">{{ __('At least 10 characters. Changing it signs you out everywhere else, and we email you to say so.') }}</p>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap">
                <button type="submit" class="btn">{{ __('Change password') }}</button>
                @if ($passwordSaved) <span class="saved-note" role="status">{{ __('Password changed.') }}</span> @endif
            </div>
        </form>

        {{-- Two-step sign-in and signed-in devices --}}
        <livewire:admin.account-security />

        <form wire:submit="saveStacy" id="stacy" style="padding-top: 20px; border-top: 1px solid var(--rule); max-width: 520px; display: flex; flex-direction: column; gap: 14px">
            <h2 class="heading" style="display: flex; align-items: center; gap: 10px"><x-admin.icon name="stacy" size="22" /> {{ __('Stacy’s model') }}</h2>
            @if ($aiServices->isEmpty())
                <p style="font-size: 14px; color: var(--muted)">
                    {{ __('No AI service is switched on yet.') }}
                    @if ($user->isAdmin()) <a href="{{ route('admin.settings.stacy') }}" wire:navigate>{{ __('Give Stacy a brain →') }}</a> @else {{ __('Ask an admin to choose one in Settings.') }} @endif
                </p>
            @else
                @php
                    $chosenService = $aiServices->firstWhere('service', $stacyService);
                    $default = $aiServices->firstWhere('is_default', true) ?? $aiServices->first();
                @endphp
                <div class="two-up">
                    <div class="field">
                        <label for="stacy-service">{{ __('Service') }}</label>
                        <select id="stacy-service" wire:model.live="stacyService">
                            <option value="">{{ __('The book’s default (:name)', ['name' => $default->name()]) }}</option>
                            @foreach ($aiServices as $service)
                                <option value="{{ $service->service }}">{{ $service->name() }}</option>
                            @endforeach
                        </select>
                        @error('stacyService') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="stacy-model">{{ __('Model') }}</label>
                        @if ($chosenService)
                            <input id="stacy-model" type="text" list="stacy-models" wire:model="stacyModel" style="font-family: var(--mono); font-size: 13px">
                            <datalist id="stacy-models">
                                @foreach ($chosenService->modelList() as $model)
                                    <option value="{{ $model }}"></option>
                                @endforeach
                            </datalist>
                        @else
                            <input id="stacy-model" type="text" value="{{ $default->default_model }}" disabled style="font-family: var(--mono); font-size: 13px">
                        @endif
                        @error('stacyModel') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap">
                    <button type="submit" class="btn">{{ __('Save choice') }}</button>
                    @if ($stacySaved) <span class="saved-note" role="status">{{ __('Stacy will use this from your next question.') }}</span> @endif
                </div>
            @endif
        </form>

        <form wire:submit="saveVoice" id="voice" class="voice-prefs" x-data="stacyVoicePicker(@js($stacyVoice))">
            <h2 class="heading" style="display: flex; align-items: center; gap: 10px"><x-admin.icon name="mic" size="22" /> {{ __('Stacy’s voice') }}</h2>
            <p style="font-size: 14px; color: var(--muted)">{!! __('Tap the mic (or press :keys) anywhere in the book and talk. These choices are just for you, and only work on this device’s browser.', ['keys' => '<kbd>Alt</kbd>+<kbd>S</kbd>']) !!}</p>

            <label class="switch-row">
                <input type="checkbox" wire:model="stacySpeak">
                <span><strong>{{ __('Answer out loud') }}</strong><small>{{ __('When you talk to Stacy, she talks back.') }}</small></span>
            </label>
            <label class="switch-row">
                <input type="checkbox" wire:model="stacyWake">
                <span><strong>{{ __('Hands-free') }}</strong><small>{{ __('Say “Stacy…” and then your question, without touching anything. Uses the microphone while the book is open.') }}</small></span>
            </label>

            <fieldset class="engine-pick">
                <legend class="field__label">{{ __('How she sounds') }}</legend>
                <label @class(['engine-option', 'is-off' => ! $naturalService])>
                    <input type="radio" name="stacy-engine" value="natural" wire:model.live="stacyEngine" @disabled(! $naturalService)>
                    <span>
                        <strong>{{ __('Natural voice') }}</strong>
                        <small>
                            @if ($naturalService)
                                {{ __('A human-sounding AI voice from :service. Costs a little per reply; the browser voice takes over if it isn’t available.', ['service' => $naturalService]) }}
                            @else
                                {{ __('Switch on OpenRouter or OpenAI in Stacy’s brain to use this.') }}
                            @endif
                        </small>
                    </span>
                </label>
                <label class="engine-option">
                    <input type="radio" name="stacy-engine" value="browser" wire:model.live="stacyEngine">
                    <span><strong>{{ __('Browser voice') }}</strong><small>{{ __('Free and instant, but it depends on your device and can sound robotic.') }}</small></span>
                </label>
            </fieldset>

            @if ($stacyEngine === 'natural' && $naturalService)
                <div class="field">
                    <label for="stacy-natural-voice">{{ __('Voice') }}</label>
                    <select id="stacy-natural-voice" wire:model="stacyNaturalVoice">
                        @foreach ($naturalVoices as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="stacy-style">{{ __('Speaking style') }}</label>
                    <textarea id="stacy-style" rows="3" wire:model="stacyStyle" placeholder="{{ $defaultStyle }}"></textarea>
                    <p class="field__hint">{{ __('Describe how she should sound, as you would to an actor. Leave empty for her usual warm, relaxed way of speaking.') }}</p>
                    @error('stacyStyle') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="two-up" @if ($stacyEngine === 'natural' && $naturalService) style="display: none" @endif>
                <div class="field">
                    <label for="stacy-voice">{{ __('Voice') }}</label>
                    <select id="stacy-voice" x-ref="voice" x-model="current" @change="$wire.stacyVoice = current" :disabled="!voices.length">
                        <option value="">{{ __('Best match for the language') }}</option>
                        <template x-for="v in voices" :key="v.id">
                            <option :value="v.id" x-text="v.name + ' · ' + v.lang" :selected="v.id === current"></option>
                        </template>
                    </select>
                    <p class="field__hint" x-show="!voices.length" x-cloak>{{ __('This browser has no voices to choose from.') }}</p>
                </div>
                <div class="field">
                    <label for="stacy-lang">{{ __('Language') }}</label>
                    <input id="stacy-lang" type="text" wire:model="stacyLang" placeholder="{{ __('en-GB') }}" list="stacy-langs" style="font-family: var(--mono); font-size: 13px">
                    <datalist id="stacy-langs">
                        @foreach (['en-US', 'en-GB', 'en-AU', 'ms-MY', 'id-ID', 'zh-CN', 'ja-JP', 'fr-FR', 'de-DE', 'es-ES'] as $lang)
                            <option value="{{ $lang }}"></option>
                        @endforeach
                    </datalist>
                    @error('stacyLang') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="field">
                <label for="stacy-rate">{{ __('Speed') }} <span style="font-family: var(--mono); font-size: 12px; color: var(--muted)" x-text="Number($wire.stacyRate).toFixed(1) + '×'"></span></label>
                <input id="stacy-rate" type="range" min="0.6" max="2" step="0.1" wire:model="stacyRate" class="range">
            </div>

            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap">
                <button type="submit" class="btn">{{ __('Save voice') }}</button>
                @if ($stacyEngine === 'natural' && $naturalService)
                    <button type="button" class="btn btn--quiet" @click="testNatural(@js(route('admin.stacy.voice')), $wire.stacyNaturalVoice, $wire.stacyStyle, $wire.stacyRate)" :disabled="loading">
                        <x-admin.icon name="speaker" size="16" /> <span x-text="loading ? @js(__('Warming up…')) : @js(__('Hear a sample'))"></span>
                    </button>
                @else
                    <button type="button" class="btn btn--quiet" @click="test(current, $wire.stacyRate, $wire.stacyLang)"><x-admin.icon name="speaker" size="16" /> {{ __('Hear a sample') }}</button>
                @endif
                @if ($voiceSaved) <span class="saved-note" role="status">{{ __('Saved. Stacy sounds like this from the next page.') }}</span> @endif
                <p class="field__error" x-show="error" x-text="error" x-cloak style="flex-basis: 100%"></p>
            </div>
        </form>

        <div id="stickers" class="sticker-book">
            <div class="section-head" style="margin-top: 0">
                <h2 class="heading" style="margin: 0">{{ __('Sticker book') }}</h2>
                <span class="muted" style="font-size: 14px">{{ $stickers->where('earned', true)->count() }} of {{ $stickers->count() }}</span>
            </div>
            @unless ($preferences['stickers'] ?? true)
                <p class="dog-ear-hint" style="margin-bottom: 12px">{{ __('Stickers are switched off, so new ones arrive quietly. Turn on “Milestone stickers” to see them on Contents.') }}</p>
            @endunless
            <ul class="sticker-book__grid">
                @foreach ($stickers as $s)
                    <li @class(['sticker-book__item', 'is-waiting' => ! $s['earned']]) wire:key="book-{{ $s['key'] }}">
                        <x-admin.milestone :s="$s" />
                        <span class="sticker-book__title">{{ $s['title'] }}</span>
                        <span class="sticker-book__meta">
                            @if ($s['earned'])
                                {{ $s['at']->translatedFormat('j M Y') }}{{ $s['scope'] === 'book' ? ' · '.__('the book') : '' }}
                            @elseif ($s['goal'] > 1)
                                {{ __(':done of :all', ['done' => number_format($s['have']), 'all' => number_format($s['goal'])]) }}
                            @else
                                {{ __($s['line']) }}
                            @endif
                        </span>
                        @if (! $s['earned'] && $s['goal'] > 1)
                            <span class="sticker-book__bar" aria-hidden="true"><span style="width: {{ round($s['have'] / $s['goal'] * 100) }}%"></span></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        <div style="padding-top: 20px; border-top: 1px solid var(--rule); max-width: 520px">
            <h2 class="eyebrow section-label">{{ __('This book') }}</h2>
            <p style="font-size: 15px; color: var(--muted)">
                {{ ['admin' => __('You’re an admin'), 'editor' => __('You’re an editor'), 'writer' => __('You’re a writer')][$user->roleNow()] ?? __('You’re a :role', ['role' => $user->roleName()]) }}
                · {{ __('joined :when', ['when' => $user->created_at?->translatedFormat('F Y')]) }}
            </p>
            <p style="margin-top: 6px; font-size: 14px"><a href="{{ route('admin.people', ['person' => $user->id]) }}" wire:navigate>{{ __('See yourself in People →') }}</a></p>
        </div>

        <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn">
                    <x-admin.icon name="logout" size="16" /> {{ __('Sign out') }}
                </button>
            </form>
            <x-admin.folio side="right" style="margin: 0; padding: 0" />
        </div>
    </section>
</div>
