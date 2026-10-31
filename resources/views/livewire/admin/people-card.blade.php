{{-- One library card (a custom role), open on the right page of People. --}}
@php
    $editing = $card !== 'new';
    $canEditCard = $this->canManage;
    $model = $editing ? \App\Models\Role::find((int) $card) : null;
@endphp
<div class="ribbon-clear" style="display: flex; justify-content: space-between; align-items: center; gap: 12px">
    <p class="eyebrow">{{ $editing ? __('Library card') : __('A new library card') }}</p>
    <button type="button" class="back" style="color: var(--ink)" wire:click="closeCard">{{ __('Close ×') }}</button>
</div>

<form wire:submit="saveCard" class="lib-editor" style="--tone: var(--{{ $cardForm['tone'] }})">
    <fieldset @disabled(! $canEditCard) style="display: contents">
        {{-- The card itself, as it looks in the pocket --}}
        <div class="lib-editor__card" aria-hidden="true">
            <span class="lib-editor__stripe"></span>
            <span class="lib-editor__title">{{ $cardForm['name'] !== '' ? $cardForm['name'] : __('Name this card') }}</span>
            <span class="lib-editor__line">{{ __('Acts like :value', ['value' => $cardForm['base'] === 'editor' ? __('an editor') : __('a writer')]) }}</span>
            <ul class="lib-editor__stamps">
                @foreach ($collections as $c)
                    @php $a = $cardForm['chapters'][$c->handle()] ?? 'write'; @endphp
                    <li @class(['lib-editor__stamp', 'lib-editor__stamp--'.$a])>{{ $c->title() }}: {{ ['none' => 'closed', 'write' => 'own drafts', 'publish' => 'publishes'][$a] ?? $a }}</li>
                @endforeach
                <li @class(['lib-editor__stamp', 'lib-editor__stamp--'.($cardForm['media'] ? 'write' : 'none')])>{{ __('Media: :value', ['value' => $cardForm['media'] ? 'can change' : 'look only']) }}</li>
                <li @class(['lib-editor__stamp', 'lib-editor__stamp--'.($cardForm['stacy'] ? 'write' : 'none')])>{{ __('Stacy: :value', ['value' => $cardForm['stacy'] ? 'yes' : 'no']) }}</li>
            </ul>
        </div>

        <div class="field">
            <label for="card-name">{{ __('Name') }}</label>
            <input id="card-name" type="text" wire:model.live.debounce.300ms="cardForm.name" placeholder="{{ __('Guest writer') }}" maxlength="40" autocomplete="off">
            @error('cardForm.name') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="card-about">{{ __('What it’s for') }} <span class="muted">{{ __('(optional)') }}</span></label>
            <input id="card-about" type="text" wire:model="cardForm.about" placeholder="{{ __('Writes for the blog now and then') }}" maxlength="140">
            @error('cardForm.about') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <fieldset class="field" style="border: 0; margin: 0; padding: 0">
            <legend class="field__label" style="padding: 0; margin-bottom: 8px">{{ __('Colour') }}</legend>
            <div style="display: flex; gap: 10px; flex-wrap: wrap" role="radiogroup" aria-label="{{ __('Colour') }}">
                @foreach (\App\Models\Role::TONES as $tone)
                    <button type="button" role="radio" class="swatch" style="--swatch: var(--{{ $tone }}); width: 34px; height: 34px; --tilt: {{ $loop->even ? '-4deg' : '4deg' }}"
                            aria-checked="{{ $cardForm['tone'] === $tone ? 'true' : 'false' }}" aria-label="{{ ucfirst($tone) }}" wire:click="$set('cardForm.tone', '{{ $tone }}')"></button>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="field" style="border: 0; margin: 0; padding: 0">
            <legend class="field__label" style="padding: 0; margin-bottom: 8px">{{ __('Acts like') }}</legend>
            <div class="role-cards" role="radiogroup" aria-label="{{ __('Acts like') }}">
                <button type="button" role="radio" class="role-card" aria-checked="{{ $cardForm['base'] === 'writer' ? 'true' : 'false' }}" wire:click="$set('cardForm.base', 'writer')">
                    <span style="display: block; font-size: 15px; font-weight: 600">{{ __('A writer') }}</span>
                    <span style="display: block; margin-top: 4px; font-size: 12px; line-height: 1.4; color: var(--faint)">{{ __('Writes own drafts and asks for review') }}</span>
                </button>
                <button type="button" role="radio" class="role-card" aria-checked="{{ $cardForm['base'] === 'editor' ? 'true' : 'false' }}" wire:click="$set('cardForm.base', 'editor')">
                    <span style="display: block; font-size: 15px; font-weight: 600">{{ __('An editor') }}</span>
                    <span style="display: block; margin-top: 4px; font-size: 12px; line-height: 1.4; color: var(--faint)">{{ __('Can publish, moderate comments, arrange menus') }}</span>
                </button>
            </div>
        </fieldset>

        <div class="field">
            <span class="field__label">{{ __('Chapters') }}</span>
            <ul class="lib-chapters" role="list">
                @foreach ($collections as $c)
                    <li wire:key="chap-{{ $c->handle() }}">
                        <label for="chap-{{ $c->handle() }}" class="lib-chapters__name"><span class="lib-chapters__dot" style="background: {{ $c->get('sticker', 'var(--sand)') }}"></span>{{ $c->title() }}</label>
                        <select id="chap-{{ $c->handle() }}" wire:model.live="cardForm.chapters.{{ $c->handle() }}">
                            @foreach (\App\Models\Role::ACCESS as $value => $label)
                                @continue($value === 'publish' && $cardForm['base'] === 'writer')
                                <option value="{{ $value }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </li>
                @endforeach
            </ul>
            @if ($cardForm['base'] === 'writer') <p class="field__hint">{{ __('Cards that act like a writer don’t publish; an editor looks over their drafts first.') }}</p> @endif
            @error('cardForm.chapters.*') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="check"><input type="checkbox" wire:model.live="cardForm.media"> {{ __('Can add and change files in Media') }}</label>
            <label class="check"><input type="checkbox" wire:model.live="cardForm.stacy"> {{ __('Can talk to Stacy') }}</label>
            <p class="field__hint">{{ __('Settings, People and backups stay for admins whatever the card says.') }}</p>
        </div>
    </fieldset>

    @if ($canEditCard)
        <div style="display: flex; gap: 10px; flex-wrap: wrap; padding-top: 8px">
            <button type="submit" class="btn btn--ink">{{ $editing ? __('Save card') : __('Make the card') }}</button>
            @if ($model)
                <button type="button" class="btn" wire:click="viewAs(@js($model->key))" title="{{ __('Only you see this, until you press Back to admin') }}">{{ __('See the book as this card') }}</button>
                @php $held = \App\Models\User::where('role', $model->key)->count(); @endphp
                <button type="button" class="btn btn--danger" wire:click="deleteCard"
                        wire:confirm="{{ __('Tear up “:name”?', ['name' => $model->name]) }}{{ $held ? ' '.trans_choice($model->base === 'editor' ? ':count person holding it will go back to being an editor.|:count people holding it will go back to being editors.' : ':count person holding it will go back to being a writer.|:count people holding it will go back to being writers.', $held) : '' }}">{{ __('Tear up') }}</button>
            @endif
        </div>
    @endif
</form>
