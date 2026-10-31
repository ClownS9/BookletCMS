@php
    $chosen = $drawer && $card === null ? $this->chosen : null;
    $me = request()->user();
    $holdersAll = $people->countBy('role');
@endphp
<div style="display: contents">
    {{-- People in the Dashboard layout: everyone in a table, roles below, and a
         drawer for one person (their role, what they've done) or one library card. --}}
    <section class="page page--single page--dash" aria-labelledby="people-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="people-title">{{ __('People') }}</h1>
                    <p>{{ trans_choice(':count person in the book|:count people in the book', $people->count()) }} · {{ __(':count online now', ['count' => $online]) }}</p>
                </div>
                @if (in_array($me->level(), ['admin', 'editor'], true))
                    <a href="{{ route('admin.readers') }}" class="btn" wire:navigate><x-admin.icon name="people" size="16" /> {{ __('Readers & groups') }}</a>
                    <a href="{{ route('admin.subscribers') }}" class="btn" wire:navigate><x-admin.icon name="mail" size="16" /> {{ __('Subscribers') }}</a>
                @endif
                @if ($this->canManage)
                    <button type="button" class="btn btn--ink" wire:click="$toggle('inviting')" aria-expanded="{{ $inviting ? 'true' : 'false' }}">
                        <x-admin.icon name="plus" size="16" /> {{ __('Invite') }}
                    </button>
                @endif
            </div>
            <x-admin.insert-links chapter="People" />

            @if ($inviting)
                <form wire:submit="invite" class="dash-card dash-invite" aria-label="{{ __('Invite someone') }}">
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
                    <div class="dash-invite__go">
                        <button type="submit" class="btn btn--ink">{{ __('Add to the book') }}</button>
                        <button type="button" class="btn btn--quiet" wire:click="$set('inviting', false)">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif

            <section class="dash-card" aria-label="{{ __('Everyone in the book') }}">
                <div class="dash-tabs" role="group" aria-label="{{ __('Filter by role') }}">
                    <button type="button" wire:click="$set('only', '')" aria-pressed="{{ $only === '' ? 'true' : 'false' }}">{{ __('Everyone') }} <b>{{ $people->count() }}</b></button>
                    @foreach ($roles as $value => $r)
                        @if (($holdersAll[$value] ?? 0) > 0 || $only === $value)
                            <button type="button" wire:click="$set('only', '{{ $value }}')" aria-pressed="{{ $only === $value ? 'true' : 'false' }}">{{ __($r['name']) }} <b>{{ $holdersAll[$value] ?? 0 }}</b></button>
                        @endif
                    @endforeach
                </div>
                <div class="dash-tools">
                    <div class="search">
                        <x-admin.icon name="search" size="16" />
                        <label for="people-search" class="sr-only">{{ __('Search people') }}</label>
                        <input id="people-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('Search by name or email') }}">
                    </div>
                </div>

                @if ($shown->isEmpty())
                    <p class="dash-empty">{{ __('Nobody matches.') }} <button type="button" class="link-button" wire:click="clearFilters">{{ __('Clear') }}</button></p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table dash-table--people">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Name') }}</th>
                                    <th scope="col">{{ __('Role') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Status') }}</th>
                                    <th scope="col" class="is-wide is-num">{{ __('Entries') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Joined') }}</th>
                                    <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shown as $person)
                                    @php $r = $roles[$person->role] ?? null; @endphp
                                    <tr wire:key="p-{{ $person->id }}" @class(['is-open' => $chosen?->id === $person->id])>
                                        <td class="dash-table__main">
                                            <button type="button" class="dash-table__open dash-person" wire:click="pick({{ $person->id }})">
                                                <x-admin.avatar :user="$person" size="32" presence />
                                                <span style="min-width: 0">
                                                    <span class="dash-table__name">{{ $person->name }}@if ($person->is($me)) <span class="is-quiet">{{ __('(you)') }}</span>@endif</span>
                                                    <span class="dash-table__sub">{{ $person->email }}</span>
                                                </span>
                                            </button>
                                        </td>
                                        <td><span class="dash-role" style="--tone: var(--{{ $r['tone'] ?? 'sand' }})">{{ $r ? __($r['name']) : $person->roleName() }}</span></td>
                                        <td class="is-wide"><x-admin.presence :user="$person" /></td>
                                        <td class="is-wide is-num">{{ number_format($written[(string) $person->id] ?? 0) }}</td>
                                        <td class="is-wide is-quiet">{{ $person->created_at?->translatedFormat('j M Y') ?? '—' }}</td>
                                        <td style="width: 1%">
                                            <div class="dash-more" x-data="bookletRowMenu()" x-on:keydown.escape="close()" x-on:click.outside="close()">
                                                <button type="button" class="icon-btn" x-ref="button" x-on:click="toggle()" :aria-expanded="open.toString()" aria-expanded="false" aria-label="{{ __('More for :name', ['name' => $person->name]) }}">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                                                </button>
                                                <div class="dash-more__menu" x-ref="menu" x-show="open" x-cloak :style="place" x-transition.opacity.duration.120ms>
                                                    <button type="button" x-on:click="open = false" wire:click="pick({{ $person->id }})">{{ $this->canManage ? __('Open and change role') : __('Open') }}</button>
                                                    @if ($person->is($me))
                                                        <a href="{{ route('admin.account') }}" wire:navigate>{{ __('Edit your account') }}</a>
                                                    @elseif ($this->canManage)
                                                        <button type="button" class="is-danger" x-on:click="open = false" wire:click="removeOne({{ $person->id }})" wire:confirm="{{ __('Remove :name from the book? Their entries stay.', ['name' => $person->name]) }}">{{ __('Remove from book') }}</button>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="dash-foot"><span>{{ __('Showing :shown of :total', ['shown' => $shown->count(), 'total' => $people->count()]) }}</span></div>
                @endif
            </section>

            {{-- Roles: the book's own, and library cards of your own --}}
            <section class="dash-card" aria-labelledby="roles-title">
                <div class="dash-card__head">
                    <h2 id="roles-title">{{ __('Roles') }}</h2>
                    @if ($this->canManage)
                        <button type="button" class="btn btn--sm" wire:click="newCard"><x-admin.icon name="plus" size="14" /> {{ __('New role') }}</button>
                    @endif
                </div>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Role') }}</th>
                                <th scope="col" class="is-wide">{{ __('What it can do') }}</th>
                                <th scope="col" class="is-num">{{ __('People') }}</th>
                                <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($roles as $value => $r)
                                @php $cardModel = $r['custom'] ? $cards->firstWhere('key', $value) : null; @endphp
                                <tr wire:key="r-{{ $value }}">
                                    <td>
                                        @if ($cardModel)
                                            <button type="button" class="dash-table__open" wire:click="editCard({{ $cardModel->id }})"><span class="dash-role" style="--tone: var(--{{ $r['tone'] }})">{{ $r['name'] }}</span></button>
                                        @else
                                            <span class="dash-role" style="--tone: var(--{{ $r['tone'] }})">{{ __($r['name']) }}</span>
                                        @endif
                                    </td>
                                    <td class="is-wide is-quiet" style="white-space: normal">{{ __($r['about']) }}@if ($cardModel) · {{ $cardModel->base === 'editor' ? __('Acts like an editor') : __('Acts like a writer') }}@endif</td>
                                    <td class="is-num">
                                        @if (($holders[$value] ?? 0) > 0)
                                            <button type="button" class="link-button" wire:click="$set('only', '{{ $value }}')">{{ $holders[$value] }}</button>
                                        @else
                                            0
                                        @endif
                                    </td>
                                    <td style="width: 1%; white-space: nowrap">
                                        @if ($cardModel)
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="editCard({{ $cardModel->id }})">{{ $this->canManage ? __('Edit') : __('Open') }}</button>
                                        @else
                                            <span class="is-quiet">{{ __('Built in') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </section>

    @if ($drawer && $card !== null)
        <x-admin.drawer :title="$card === 'new' ? __('A new role') : __('Role')" close="closeCard" class="drawer__panel--card">
            @include('livewire.admin.people-card')
        </x-admin.drawer>
    @elseif ($chosen)
        <x-admin.drawer :title="$chosen->name">
            @if ($temporaryPassword)
                <div class="empty-note" role="status" style="font-size: 15px">
                    {{ $chosen->name }} can sign in with <strong>{{ $chosen->email }}</strong> {{ __('and this temporary password:') }}
                    <code style="display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 4px; background: rgba(255,255,255,.6); font-family: var(--mono); font-size: 15px">{{ $temporaryPassword }}</code>
                    <span style="display: block; margin-top: 6px; font-size: 13px">{{ __('It’s shown only once. They can change it from their account page.') }}</span>
                </div>
            @endif

            <div class="drawer-person">
                <x-admin.avatar :user="$chosen" size="64" presence />
                <div style="min-width: 0">
                    <p class="drawer-person__email">{{ $chosen->email }}</p>
                    <x-admin.presence :user="$chosen" class="presence--inline" />
                </div>
            </div>

            <dl class="drawer-stats">
                <div><dt>{{ __('Entries') }}</dt><dd>{{ $stats['entries'] }}</dd></div>
                <div><dt>{{ __('Drafts') }}</dt><dd>{{ $stats['drafts'] }}</dd></div>
                <div><dt>{{ __('Joined') }}</dt><dd>{{ $stats['joined'] ?? '—' }}</dd></div>
            </dl>

            <h3 class="field__label">{{ __('Role') }}</h3>
            <div class="role-cards role-cards--drawer" role="radiogroup" aria-label="{{ __('Role') }}">
                @foreach ($roles as $value => $r)
                    <button type="button" role="radio" @class(['role-card', 'role-card--custom' => $r['custom']]) style="--tone: var(--{{ $r['tone'] }})" aria-checked="{{ $role === $value ? 'true' : 'false' }}"
                            wire:click="$set('role', '{{ $value }}')" @disabled(! $this->canManage)>
                        <span style="display: block; font-size: 14px; font-weight: 600">{{ __($r['name']) }}</span>
                        <span style="display: block; margin-top: 3px; font-size: 12px; line-height: 1.4; color: var(--faint)">{{ __($r['about']) }}</span>
                    </button>
                @endforeach
            </div>
            @error('role') <p class="field__error">{{ $message }}</p> @enderror

            <h3 class="field__label">{{ __('Lately') }}</h3>
            @if ($lately->isEmpty())
                <p class="field__hint" style="margin: 0">{{ __('No stamps on the card yet.') }}</p>
            @else
                <ul class="drawer-lately" role="list">
                    @foreach ($lately as $log)
                        <li><span>{{ \Illuminate\Support\Str::ucfirst(__($log->action)) }} <em>{{ $log->subject_label }}</em></span><time>{{ $log->created_at->diffForHumans() }}</time></li>
                    @endforeach
                </ul>
            @endif

            <div class="drawer__actions">
                @if ($this->canManage)
                    <button type="button" class="btn btn--ink" wire:click="saveRole" @disabled($role === $chosen->role)>{{ __('Save role') }}</button>
                    @if (! $chosen->is($me) && \App\Support\TwoFactor::on($chosen))
                        <button type="button" class="btn" wire:click="resetTwoFactor"
                                wire:confirm="{{ __('Reset two-step sign-in for :name? Only do this if they’ve lost their phone and recovery codes, and you’re sure it’s them asking.', ['name' => $chosen->name]) }}">{{ __('Reset two-step sign-in') }}</button>
                    @endif
                    @unless ($chosen->is($me))
                        <button type="button" class="btn btn--danger" wire:click="remove" wire:confirm="{{ __('Remove :name from the book? Their entries stay.', ['name' => $chosen->name]) }}">{{ __('Remove from book') }}</button>
                    @endunless
                @else
                    <p class="field__hint" style="margin: 0">{{ __('Only admins can change roles.') }}</p>
                @endif
                @if ($chosen->is($me))
                    <a href="{{ route('admin.account') }}" class="btn" wire:navigate>{{ __('Edit your account') }}</a>
                @endif
            </div>
        </x-admin.drawer>
    @endif
</div>
