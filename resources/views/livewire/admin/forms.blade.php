<div style="display: contents">
    {{-- Left page: the forms --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <a href="{{ route('admin.inbox') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Inbox') }}</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Forms') }}</h1>
            <p class="lede" style="margin-top: 12px; font-size: 15px">{{ __('Forms of your own: a sign-up, an RSVP, an order. Put one on any page with the page builder; answers arrive in the Inbox.') }}</p>
        </div>

        @if ($forms->isEmpty())
            <p class="empty-note">{{ __('No forms yet. Make your first one below.') }}</p>
        @else
            <ul class="picks">
                @foreach ($forms as $f)
                    <li wire:key="form-{{ $f['handle'] }}">
                        <button type="button" class="pick" wire:click="pick('{{ $f['handle'] }}')" aria-pressed="{{ $chosen === $f['handle'] ? 'true' : 'false' }}">
                            <span class="dot" style="--dot: {{ $f['open'] ? 'var(--mint)' : 'var(--sand)' }}; --tilt: 0deg" aria-hidden="true"></span>
                            <span style="flex: 1 1 auto; min-width: 0">
                                <span class="serif" style="display: block; font-size: 17px; overflow-wrap: anywhere">{{ $f['name'] }}</span>
                                <span style="display: block; font-size: 13px; color: var(--faint)">{{ trans_choice(':count answer|:count answers', $f['answers']) }} · {{ $f['open'] ? __('Taking answers') : __('Closed') }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <form wire:submit="create" class="forms-new">
            <label for="form-new" class="field__label">{{ __('A new form') }}</label>
            <div class="forms-new__row">
                <input id="form-new" type="text" class="input" wire:model="newName" maxlength="80" placeholder="{{ __('Bake sale sign-up, Wedding RSVP…') }}">
                <button type="submit" class="btn btn--ink" wire:loading.attr="disabled" wire:target="create">{{ __('Make it') }}</button>
            </div>
            @error('newName') <p class="field__error">{{ $message }}</p> @enderror
            <p class="field__hint" style="margin: 0">{{ __('It starts with a name, an email and a box for anything else. Change them as you like.') }}</p>
        </form>

        <x-admin.folio side="left" style="margin-top: auto" />
    </section>

    {{-- Right page: one form, its questions and words --}}
    <section class="page page--right page--stack" style="--ribbon: var(--rose)">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($form)
            <form wire:submit="save" class="forms-edit ribbon-clear">
                <div class="field">
                    <label for="form-name">{{ __('Its name') }}</label>
                    <input id="form-name" type="text" class="forms-edit__name" wire:model="name" maxlength="80">
                    @error('name') <p class="field__error">{{ $message }}</p> @enderror
                    <p class="field__hint">
                        {{ trans_choice(':count answer|:count answers', $answers) }}@if ($unread) · {{ __(':count new', ['count' => $unread]) }} @endif
                        @if ($answers) · <a href="{{ route('admin.inbox', ['from' => $form->handle()]) }}" wire:navigate>{{ __('Read them in the Inbox →') }}</a>
                            · <a href="{{ route('admin.forms.csv', $form->handle()) }}">{{ __('Download them (CSV)') }}</a> @endif
                    </p>
                </div>

                <h2 class="eyebrow section-label" style="margin: 8px 0 0">{{ __('Questions') }}</h2>
                @error('fields') <p class="field__error">{{ $message }}</p> @enderror
                <ol class="forms-qs">
                    @foreach ($fields as $i => $q)
                        <li class="forms-q" wire:key="q-{{ $i }}-{{ $q['key'] ?: 'new' }}">
                            <div class="forms-q__top">
                                <span class="forms-q__n" aria-hidden="true">{{ $i + 1 }}</span>
                                <label class="sr-only" for="q-{{ $i }}-type">{{ __('Kind of answer') }}</label>
                                <select id="q-{{ $i }}-type" class="input forms-q__type" wire:model.live="fields.{{ $i }}.type">
                                    @foreach ($kinds as $k => $words) <option value="{{ $k }}">{{ __($words) }}</option> @endforeach
                                </select>
                                <label class="forms-q__need"><input type="checkbox" wire:model="fields.{{ $i }}.required"> {{ __('Must be answered') }}</label>
                                <span class="forms-q__tools">
                                    <button type="button" class="icon-btn" wire:click="moveField({{ $i }}, -1)" @disabled($loop->first) aria-label="Move question {{ $i + 1 }} up" title="{{ __('Move up') }}">↑</button>
                                    <button type="button" class="icon-btn" wire:click="moveField({{ $i }}, 1)" @disabled($loop->last) aria-label="Move question {{ $i + 1 }} down" title="{{ __('Move down') }}">↓</button>
                                    <button type="button" class="icon-btn" wire:click="removeField({{ $i }})" aria-label="Take out question {{ $i + 1 }}" title="{{ __('Take it out') }}">×</button>
                                </span>
                            </div>
                            <label class="sr-only" for="q-{{ $i }}-label">{{ __('Question :n', ['n' => $i + 1]) }}</label>
                            <input id="q-{{ $i }}-label" type="text" class="input" wire:model="fields.{{ $i }}.label" maxlength="160" placeholder="{{ __('The question, as readers see it') }}">
                            @error('fields.'.$i.'.label') <p class="field__error">{{ $message }}</p> @enderror
                            @if (in_array($q['type'], ['choice', 'choices'], true))
                                <label class="forms-q__sub" for="q-{{ $i }}-options">{{ __('Choices, one a line') }}</label>
                                <textarea id="q-{{ $i }}-options" class="input" rows="3" wire:model="fields.{{ $i }}.options"></textarea>
                                @error('fields.'.$i.'.options') <p class="field__error">{{ $message }}</p> @enderror
                            @endif
                            <label class="sr-only" for="q-{{ $i }}-help">{{ __('A hint under question :n', ['n' => $i + 1]) }}</label>
                            <input id="q-{{ $i }}-help" type="text" class="input forms-q__help" wire:model="fields.{{ $i }}.help" maxlength="200" placeholder="{{ __('A hint under it (optional)') }}">
                        </li>
                    @endforeach
                </ol>

                <div class="forms-add" role="group" aria-label="{{ __('Add a question') }}">
                    <span class="field__label" style="margin: 0">{{ __('Add a question:') }}</span>
                    @foreach ($kinds as $k => $words)
                        <button type="button" class="pill" wire:click="addField('{{ $k }}')" @disabled($max)>+ {{ __($words) }}</button>
                    @endforeach
                </div>

                <h2 class="eyebrow section-label" style="margin: 14px 0 0">{{ __('Once it’s sent') }}</h2>
                <div class="field">
                    <label for="form-thanks">{{ __('What readers see') }}</label>
                    <textarea id="form-thanks" class="input" rows="2" wire:model="thanks" maxlength="600"></textarea>
                </div>
                <div class="field">
                    <label for="form-button">{{ __('The button’s words') }}</label>
                    <input id="form-button" type="text" class="input" wire:model="button" maxlength="40" placeholder="{{ __('Send') }}" style="max-width: 260px">
                </div>

                <div class="setting-rows">
                    <div class="setting-row">
                        <span>
                            <span id="form-open" style="display: block; font-size: 15px; font-weight: 500">{{ __('Taking answers') }}</span>
                            <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Off: pages with it say it’s closed for now.') }}</span>
                        </span>
                        <button type="button" class="switch" role="switch" aria-labelledby="form-open" aria-checked="{{ $open ? 'true' : 'false' }}" wire:click="$toggle('open')"></button>
                    </div>
                    <div class="setting-row">
                        <span>
                            <span id="form-notify" style="display: block; font-size: 15px; font-weight: 500">{{ __('Email the team about each answer') }}</span>
                            <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Admins and editors who want messages by email (Your account).') }}</span>
                        </span>
                        <button type="button" class="switch" role="switch" aria-labelledby="form-notify" aria-checked="{{ $notify ? 'true' : 'false' }}" wire:click="$toggle('notify')"></button>
                    </div>
                </div>

                <div class="forms-where">
                    @if ($usedOn === [])
                        <p class="field__hint" style="margin: 0">{!! __('Not on a page yet. In the page builder: :where, then choose “:name”.', ['where' => '<b>'.e(__('Add a section › A form')).'</b>', 'name' => e($form->title())]) !!}</p>
                    @else
                        <p class="field__hint" style="margin: 0">On
                            @foreach ($usedOn as [$title, $uri])
                                @if ($uri)<a href="{{ url($uri) }}" target="_blank" rel="noopener">{{ $title }} ↗</a>@else{{ $title }}@endif{{ $loop->last ? '.' : ', ' }}
                            @endforeach
                        </p>
                    @endif
                </div>

                <div class="forms-edit__foot">
                    <button type="submit" class="btn btn--ink" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ __('Save') }}</span><span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </button>
                    <button type="button" class="btn btn--danger btn--sm" wire:click="delete"
                            wire:confirm="{{ trans_choice('Delete “:name” and its :count answer? Pages with it stop showing it.|Delete “:name” and its :count answers? Pages with it stop showing it.', $answers, ['name' => $form->title()]) }}">{{ __('Delete this form') }}</button>
                </div>
            </form>
        @else
            <p class="empty-note ribbon-clear" style="max-width: 460px">{{ __('Make a form on the left, and its questions show up here.') }}</p>
        @endif
        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
