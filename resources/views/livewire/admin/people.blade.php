@php $chosen = $this->chosen; @endphp

<div style="display: contents">
    {{-- Left page: everyone --}}
    <section class="page page--left" style="flex-basis: 400px">
        <div class="page-head" style="display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <x-admin.crumbs />
                <p class="eyebrow">{{ __(':value · :online online now', ['value' => \App\Support\AdminNav::chapterLabel(), 'online' => $online]) }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('People') }}</h1>
            </div>
            @if ($this->canManage)
                <button type="button" class="btn btn--ink" wire:click="$toggle('inviting')" aria-expanded="{{ $inviting ? 'true' : 'false' }}">
                    <x-admin.icon name="plus" size="16" /> {{ __('Invite') }}
                </button>
            @endif
        </div>
        <x-admin.insert-links chapter="People" />
        @if (in_array(request()->user()->level(), ['admin', 'editor'], true))
            <div class="chapter-links has-handheld-twin">
                <a href="{{ route('admin.subscribers') }}" class="back" wire:navigate><x-admin.icon name="mail" size="16" /> {{ __('Subscribers →') }}</a>
                <a href="{{ route('admin.readers') }}" class="back" wire:navigate><x-admin.icon name="people" size="16" /> {{ __('Readers & groups →') }}</a>
            </div>
        @endif

        @if ($inviting)
            <form wire:submit="invite" style="margin-top: 20px; padding: 18px; border: 1px solid var(--rule); border-radius: 12px; background: var(--paper-3); display: flex; flex-direction: column; gap: 12px">
                <div class="field">
                    <label for="invite-name">{{ __('Name') }}</label>
                    <input id="invite-name" type="text" wire:model="inviteName" autocomplete="off">
                    @error('inviteName') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="invite-email">{{ __('Email') }}</label>
                    <input id="invite-email" type="email" wire:model="inviteEmail" autocomplete="off">
                    @error('inviteEmail') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="invite-role">{{ __('Role') }}</label>
                    <select id="invite-role" wire:model="inviteRole">
                        @foreach ($roles as $value => $r)
                            <option value="{{ $value }}">{{ $r['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="display: flex; gap: 8px">
                    <button type="submit" class="btn btn--ink">{{ __('Add to the book') }}</button>
                    <button type="button" class="btn btn--quiet" wire:click="$set('inviting', false)">{{ __('Cancel') }}</button>
                </div>
            </form>
        @endif

        <ul class="picks" style="margin-top: 22px">
            @foreach ($people as $person)
                <li wire:key="person-{{ $person->id }}">
                    <button type="button" class="pick" style="align-items: center" wire:click="pick({{ $person->id }})" aria-pressed="{{ $chosen?->id === $person->id ? 'true' : 'false' }}">
                        <x-admin.avatar :user="$person" size="44" presence />
                        <span style="flex: 1 1 auto; min-width: 0">
                            <span class="pick__title">{{ $person->name }}@if ($person->is(request()->user())) <span class="muted" style="font-size: 15px">{{ __('(you)') }}</span>@endif</span>
                            <x-admin.presence :user="$person" />
                        </span>
                        <span style="font-size: 13px; color: var(--muted)">{{ $person->roleName() }}</span>
                    </button>
                </li>
            @endforeach
        </ul>

        {{-- Library cards: custom roles --}}
        <div class="cards-shelf">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px">
                <h2 class="eyebrow section-label" style="margin: 0">{{ __('Library cards') }}</h2>
                @if ($this->canManage)
                    <button type="button" class="link-button" style="font-size: 14px" wire:click="newCard">{{ __('+ Make a card') }}</button>
                @endif
            </div>
            @if ($cards->isEmpty())
                <p class="field__hint" style="margin-top: 8px">{{ __('Roles of your own, like “Guest writer: writes in Posts, can’t open Pages”. Each one acts like a writer or an editor, then narrows what it opens.') }}</p>
            @else
                <ul class="lib-cards" role="list">
                    @foreach ($cards as $i => $c)
                        <li wire:key="card-{{ $c->id }}">
                            <button type="button" class="lib-card" style="--tone: var(--{{ $c->tone }}); --tilt: {{ ['-1.2deg', '0.8deg', '-0.4deg', '1.1deg'][$i % 4] }}"
                                    wire:click="editCard({{ $c->id }})" aria-pressed="{{ $card === (string) $c->id ? 'true' : 'false' }}">
                                <span class="lib-card__name">{{ $c->name }}</span>
                                <span class="lib-card__meta">{{ $c->base === 'editor' ? __('Acts like an editor') : __('Acts like a writer') }} · {{ trans_choice(':count holder|:count holders', $holders[$c->key] ?? 0) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: one person --}}
    <section class="page page--right" style="--ribbon: var(--lilac)">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($card !== null)
            @include('livewire.admin.people-card')
        @elseif ($chosen)
            @if ($temporaryPassword)
                <div class="empty-note" role="status" style="margin-bottom: 24px; font-size: 17px">
                    {{ $chosen->name }} can sign in with <strong>{{ $chosen->email }}</strong> {{ __('and this temporary password:') }}
                    <code style="display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 4px; background: rgba(255,255,255,.6); font-family: var(--mono); font-size: 15px">{{ $temporaryPassword }}</code>
                    <span style="display: block; margin-top: 6px; font-family: var(--sans); font-size: 13px">{{ __('It’s shown only once. They can change it from their account page.') }}</span>
                </div>
            @endif

            <div class="ribbon-clear" style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap">
                <x-admin.avatar :user="$chosen" size="88" presence />
                <div style="min-width: 0">
                    <h2 class="display display--person">{{ $chosen->name }}</h2>
                    <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">
                        {{ $chosen->email }}
                    </p>
                    <p style="margin-top: 6px">
                        <x-admin.presence :user="$chosen" class="presence--inline" />
                    </p>
                </div>
            </div>

            <dl style="margin: 30px 0 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border-top: 1px solid var(--rule); border-bottom: 1px solid var(--rule)">
                <div style="padding: 16px 0"><dt class="eyebrow" style="font-size: 11px">{{ __('Entries') }}</dt><dd class="serif" style="margin: 6px 0 0; font-size: 30px">{{ $stats['entries'] }}</dd></div>
                <div style="padding: 16px 0"><dt class="eyebrow" style="font-size: 11px">{{ __('Drafts') }}</dt><dd class="serif" style="margin: 6px 0 0; font-size: 30px">{{ $stats['drafts'] }}</dd></div>
                <div style="padding: 16px 0"><dt class="eyebrow" style="font-size: 11px">{{ __('Joined') }}</dt><dd class="serif" style="margin: 6px 0 0; font-size: 30px">{{ $stats['joined'] ?? '—' }}</dd></div>
            </dl>

            <h3 class="field__label" style="margin: 26px 0 12px">{{ __('Role') }}</h3>
            <div class="role-cards" role="radiogroup" aria-label="{{ __('Role') }}">
                @foreach ($roles as $value => $r)
                    <button type="button" role="radio" @class(['role-card', 'role-card--custom' => $r['custom']]) style="--tone: var(--{{ $r['tone'] }})" aria-checked="{{ $role === $value ? 'true' : 'false' }}"
                            wire:click="$set('role', '{{ $value }}')" @disabled(! $this->canManage)>
                        <span style="display: block; font-size: 15px; font-weight: 600">{{ $r['name'] }}</span>
                        <span style="display: block; margin-top: 4px; font-size: 12px; line-height: 1.4; color: var(--faint)">{{ __($r['about']) }}</span>
                    </button>
                @endforeach
            </div>
            @error('role') <p class="field__error" style="margin-top: 8px">{{ $message }}</p> @enderror

            <h3 class="eyebrow section-label section-label--gap">{{ __('Lately') }}</h3>
            @if ($lately->isEmpty())
                <p class="muted serif" style="font-style: italic; font-size: 17px">{{ __('No stamps on the card yet.') }}</p>
            @else
                <ul class="rows">
                    @foreach ($lately as $log)
                        <li style="display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; font-size: 15px">
                            <span>{{ \Illuminate\Support\Str::ucfirst(__($log->action)) }} <em class="serif" style="font-size: 17px">{{ $log->subject_label }}</em></span>
                            <span class="muted" style="flex: none; font-size: 13px">{{ $log->created_at->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div style="margin-top: auto; padding-top: 32px; display: flex; gap: 10px; flex-wrap: wrap">
                @if ($this->canManage)
                    <button type="button" class="btn btn--ink" wire:click="saveRole" @disabled($role === $chosen->role)>{{ __('Save role') }}</button>
                    @if (! $chosen->is(request()->user()) && \App\Support\TwoFactor::on($chosen))
                        <button type="button" class="btn" wire:click="resetTwoFactor"
                                wire:confirm="Reset two-step sign-in for {{ $chosen->name }}? Only do this if they’ve lost their phone and recovery codes, and you’re sure it’s them asking.">{{ __('Reset two-step sign-in') }}</button>
                    @endif
                    @unless ($chosen->is(request()->user()))
                        <button type="button" class="btn btn--danger" wire:click="remove" wire:confirm="Remove {{ $chosen->name }} from the book? Their entries stay.">{{ __('Remove from book') }}</button>
                    @endunless
                @else
                    <p class="muted" style="font-size: 14px">{{ __('Only admins can change roles.') }}</p>
                @endif
                @if ($chosen->is(request()->user()))
                    <a href="{{ route('admin.account') }}" class="btn" wire:navigate>{{ __('Edit your account') }}</a>
                @endif
            </div>
        @else
            <p class="empty-note">{{ __('Pick someone on the left.') }}</p>
        @endif

        <x-admin.folio side="right" />
    </section>
</div>
