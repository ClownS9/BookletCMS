@php
    $stateNames = ['active' => 'In', 'waiting' => 'Waiting', 'unconfirmed' => 'Not confirmed', 'blocked' => 'Blocked'];
    $stateDots = ['active' => 'published', 'waiting' => 'review', 'unconfirmed' => 'scheduled', 'blocked' => 'draft'];
    $pageIds = $readers->getCollection()->pluck('id')->all();
    $groupNames = $groups->keyBy('id');
@endphp
<div style="display: contents">
    {{-- Readers in the Dashboard layout: who's waiting, then everyone in a table
         (ticks for many at once, a drawer for one), and the groups and joining tabs. --}}
    <section class="page page--single page--dash" aria-labelledby="readers-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="readers-title">{{ __('Readers & groups') }}</h1>
                    <p>{{ __('People with an account on your site. They can read what’s open to members, but never this book.') }}</p>
                </div>
                <button type="button" class="btn btn--ink" wire:click="$toggle('inviting')" aria-expanded="{{ $inviting ? 'true' : 'false' }}"><x-admin.icon name="plus" size="16" /> {{ __('Invite') }}</button>
            </div>

            @if ($joiningNow === 'off' && $counts['active'] + $counts['waiting'] + $counts['unconfirmed'] === 0)
                <div class="vc-note">
                    <p><strong>{{ __('Nobody can join yet.') }}</strong> {{ $isAdmin ? __('Choose how people join, and “Sign in” appears on the site.') : __('An admin chooses how people join.') }}</p>
                    @if ($isAdmin) <button type="button" class="btn btn--sm" wire:click="setTab('joining')">{{ __('How people join') }}</button> @endif
                </div>
            @endif

            @if ($inviting)
                <form wire:submit="invite" class="dash-card dash-invite" aria-label="{{ __('Invite someone') }}">
                    <div class="field">
                        <label for="invite-email">{{ __('Their email address') }}</label>
                        <input id="invite-email" type="email" class="input" wire:model="inviteEmail" placeholder="reader@example.com" autocomplete="off">
                        @error('inviteEmail') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="invite-name">{{ __('Their name') }} <span class="muted">{{ __('(optional)') }}</span></label>
                        <input id="invite-name" type="text" class="input" wire:model="inviteName" maxlength="120" autocomplete="off">
                    </div>
                    @if ($groups->isNotEmpty())
                        <fieldset class="reader-invite__groups">
                            <legend class="field__label">{{ __('Put them in') }}</legend>
                            @foreach ($groups as $g)
                                <label class="open-to__choice"><input type="checkbox" wire:model="inviteGroups" value="{{ $g->handle }}"> {{ $g->name }}</label>
                            @endforeach
                        </fieldset>
                    @endif
                    <div class="dash-invite__go">
                        <button type="submit" class="btn btn--ink">{{ __('Send an invitation') }}</button>
                        <button type="button" class="btn btn--quiet" wire:click="$set('inviting', false)">{{ __('Cancel') }}</button>
                    </div>
                    <p class="field__hint" style="grid-column: 1 / -1; margin: 0">{{ __('A link to make their account, good for :days days. It works even when joining is by invitation only.', ['days' => \App\Support\ReaderAccounts::INVITE_DAYS]) }}</p>
                </form>
            @endif

            @if ($waiting->isNotEmpty() || $asks->isNotEmpty())
                <section class="dash-card" aria-labelledby="waiting-title">
                    <div class="dash-card__head"><h2 id="waiting-title">{{ __('Waiting for you') }}</h2><small>{{ $waiting->count() + $asks->count() }}</small></div>
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <tbody>
                                @foreach ($waiting as $r)
                                    <tr wire:key="wait-{{ $r->id }}">
                                        <td><span class="dash-table__name">{{ $r->name }}</span><span class="dash-table__sub dash-table__sub--plain">{{ $r->email }}</span></td>
                                        <td class="is-wide is-quiet">{{ __('asked to join the site :when', ['when' => $r->created_at->diffForHumans()]) }}</td>
                                        <td class="dash-table__actions">
                                            <button type="button" class="btn btn--sm btn--ink" wire:click="letIn({{ $r->id }})">{{ __('Let them in') }}</button>
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="turnAway({{ $r->id }})" wire:confirm="{{ __('Turn :name away? Their account is deleted.', ['name' => $r->name]) }}">{{ __('Turn away') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                                @foreach ($asks as $row)
                                    <tr wire:key="ask-{{ $row['member']->id }}">
                                        <td><span class="dash-table__name">{{ $row['who']->name }}</span><span class="dash-table__sub dash-table__sub--plain">{{ $row['who']->email }}</span></td>
                                        <td class="is-wide is-quiet">{{ __('asked to join :group', ['group' => $row['member']->group?->name]) }} · {{ $row['member']->created_at->diffForHumans() }}</td>
                                        <td class="dash-table__actions">
                                            <button type="button" class="btn btn--sm btn--ink" wire:click="answerAsk({{ $row['member']->id }}, true)">{{ __('Let them in') }}</button>
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="answerAsk({{ $row['member']->id }}, false)">{{ __('Say no') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="dash-card" aria-label="{{ __('Readers & groups') }}">
                <div class="dash-tabs dash-tabs--top" role="tablist" aria-label="{{ __('Show') }}">
                    <button type="button" role="tab" wire:click="setTab('readers')" aria-selected="{{ $tab === 'readers' ? 'true' : 'false' }}" aria-pressed="{{ $tab === 'readers' ? 'true' : 'false' }}">{{ __('Readers') }} <b>{{ number_format(array_sum($counts)) }}</b></button>
                    <button type="button" role="tab" wire:click="setTab('groups')" aria-selected="{{ $tab === 'groups' ? 'true' : 'false' }}" aria-pressed="{{ $tab === 'groups' ? 'true' : 'false' }}">{{ __('Groups') }} <b>{{ $groups->count() }}</b></button>
                    @if ($isAdmin)
                        <button type="button" role="tab" wire:click="setTab('joining')" aria-selected="{{ $tab === 'joining' ? 'true' : 'false' }}" aria-pressed="{{ $tab === 'joining' ? 'true' : 'false' }}">{{ __('How people join') }}</button>
                    @endif
                </div>

                @if ($tab === 'readers')
                    <div class="dash-subtabs" role="group" aria-label="{{ __('Show') }}">
                        @foreach (['all' => 'Everyone', 'active' => 'In', 'waiting' => 'Waiting', 'unconfirmed' => 'Not confirmed', 'blocked' => 'Blocked'] as $value => $label)
                            <button type="button" class="pill" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}">{{ __($label) }} <b>{{ number_format($value === 'all' ? array_sum($counts) : ($counts[$value] ?? 0)) }}</b></button>
                        @endforeach
                    </div>

                    @if ($ticked === [])
                        <div class="dash-tools">
                            <div class="search">
                                <x-admin.icon name="search" size="16" />
                                <label for="reader-search" class="sr-only">{{ __('Find by name or email') }}</label>
                                <input id="reader-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find by name or email') }}">
                            </div>
                            @if ($groups->isNotEmpty())
                                <label for="reader-in-group" class="sr-only">{{ __('In a group') }}</label>
                                <select id="reader-in-group" wire:model.live="inGroup" class="input" style="width: auto">
                                    <option value="">{{ __('Any group') }}</option>
                                    @foreach ($groups as $g) <option value="{{ $g->handle }}">{{ $g->name }}</option> @endforeach
                                </select>
                            @endif
                        </div>
                    @else
                        <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                            <strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
                            <button type="button" class="btn btn--sm" wire:click="doTicked('letIn')">{{ __('Let them in') }}</button>
                            <button type="button" class="btn btn--sm" wire:click="doTicked('block')" wire:confirm="{{ __('Block them? They can’t sign in until you unblock them.') }}">{{ __('Block') }}</button>
                            <button type="button" class="btn btn--sm" wire:click="doTicked('unblock')">{{ __('Unblock') }}</button>
                            <button type="button" class="btn btn--danger btn--sm" wire:click="doTicked('remove')" wire:confirm="{{ trans_choice('Remove :count account? This can’t be undone.|Remove :count accounts? This can’t be undone.', count($ticked)) }}">{{ __('Remove') }}</button>
                            <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('ticked', [])">{{ __('Clear') }}</button>
                        </div>
                    @endif

                    @if ($readers->isEmpty())
                        <p class="dash-empty">{{ $search !== '' || $show !== 'all' || $inGroup !== '' ? __('Nobody here.') : __('No readers yet. Once people can join, they appear here.') }}</p>
                    @else
                        <div class="dash-table-wrap">
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th scope="col" class="dash-table__tick"><input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked($pageIds !== [] && array_diff($pageIds, $ticked) === []) aria-label="{{ __('Choose all on this page') }}"></th>
                                        <th scope="col">{{ __('Name') }}</th>
                                        <th scope="col">{{ __('Status') }}</th>
                                        <th scope="col" class="is-wide">{{ __('Groups') }}</th>
                                        <th scope="col" class="is-wide">{{ __('Joined') }}</th>
                                        <th scope="col" class="is-wide">{{ __('Last signed in') }}</th>
                                        <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($readers as $r)
                                        @php $in = $memberGroups->get($r->id, collect())->where('state', 'in'); @endphp
                                        <tr wire:key="reader-{{ $r->id }}" @class(['is-ticked' => in_array($r->id, $ticked, true), 'is-open' => $open === $r->id])>
                                            <td class="dash-table__tick"><input type="checkbox" wire:model.live="ticked" value="{{ $r->id }}" aria-label="{{ __('Choose :name', ['name' => $r->name]) }}"></td>
                                            <td class="dash-table__main">
                                                <button type="button" class="dash-table__open" wire:click="toggle({{ $r->id }})">
                                                    <span class="dash-table__name">{{ $r->name }}</span>
                                                    <span class="dash-table__sub dash-table__sub--plain">{{ $r->email }}</span>
                                                </button>
                                            </td>
                                            <td><span class="dash-status dash-status--{{ $stateDots[$r->state] ?? 'draft' }}"><i aria-hidden="true"></i>{{ __($stateNames[$r->state] ?? $r->state) }}</span></td>
                                            <td class="is-wide">
                                                @forelse ($in->take(2) as $m)<span class="dash-tag">{{ $groupNames[$m->group_id]->name ?? '' }}</span>@empty<span class="is-quiet">—</span>@endforelse
                                                @if ($in->count() > 2)<span class="is-quiet">+{{ $in->count() - 2 }}</span>@endif
                                            </td>
                                            <td class="is-wide is-quiet">{{ $r->created_at->translatedFormat('j M Y') }}</td>
                                            <td class="is-wide is-quiet">{{ $r->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                            <td style="width: 1%">
                                                <div class="dash-more" x-data="bookletRowMenu()" x-on:keydown.escape="close()" x-on:click.outside="close()">
                                                    <button type="button" class="icon-btn" x-ref="button" x-on:click="toggle()" :aria-expanded="open.toString()" aria-expanded="false" aria-label="{{ __('More for :name', ['name' => $r->name]) }}">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                                                    </button>
                                                    <div class="dash-more__menu" x-ref="menu" x-show="open" x-cloak :style="place" x-transition.opacity.duration.120ms>
                                                        <button type="button" x-on:click="open = false" wire:click="toggle({{ $r->id }})">{{ __('Open') }}</button>
                                                        @if (in_array($r->state, ['waiting', 'unconfirmed'], true))<button type="button" x-on:click="open = false" wire:click="letIn({{ $r->id }})">{{ __('Let them in') }}</button>@endif
                                                        @if ($r->state === 'unconfirmed')<button type="button" x-on:click="open = false" wire:click="confirmAgain({{ $r->id }})">{{ __('Send the confirm email again') }}</button>@endif
                                                        @if ($r->state === 'blocked')
                                                            <button type="button" x-on:click="open = false" wire:click="unblock({{ $r->id }})">{{ __('Unblock') }}</button>
                                                        @else
                                                            <button type="button" x-on:click="open = false" wire:click="block({{ $r->id }})" wire:confirm="{{ __('Block :name? They can’t sign in until you unblock them.', ['name' => $r->name]) }}">{{ __('Block') }}</button>
                                                        @endif
                                                        <button type="button" class="is-danger" x-on:click="open = false" wire:click="remove({{ $r->id }})" wire:confirm="{{ __('Remove :name’s account? This can’t be undone.', ['name' => $r->name]) }}">{{ __('Remove') }}</button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <x-admin.pager :of="$readers" :sizes="$pageSizes" class="dash-foot" id="readers-pager" always />
                    @endif
                @else
                    <div class="dash-card__pad">
                        @include('livewire.admin.partials.readers-groups')
                    </div>
                @endif
            </section>
        </div>
    </section>

    @if ($chosenReader)
        @php $r = $chosenReader; @endphp
        <x-admin.drawer :title="$r->name">
            <p class="drawer__meta">
                {{ $r->email }}
                <span class="dash-status dash-status--{{ $stateDots[$r->state] ?? 'draft' }}"><i aria-hidden="true"></i>{{ __($stateNames[$r->state] ?? $r->state) }}</span>
            </p>
            <dl class="drawer-stats">
                <div><dt>{{ __('Joined') }}</dt><dd style="font-size: 16px">{{ $r->created_at->translatedFormat('j M Y') }}</dd></div>
                <div><dt>{{ __('Last signed in') }}</dt><dd style="font-size: 16px">{{ $r->last_seen_at?->diffForHumans() ?? '—' }}</dd></div>
                <div><dt>{{ __('Groups') }}</dt><dd>{{ $chosenGroups->where('state', 'in')->count() }}</dd></div>
            </dl>
            @if (! empty($r->answers) && $questionList !== [])
                <h3 class="field__label">{{ __('Their answers') }}</h3>
                <dl class="reader-answers">
                    @foreach ($questionList as $q)
                        @continue(! array_key_exists($q['key'], $r->answers))
                        <div><dt>{{ $q['label'] }}</dt><dd>{{ $q['type'] === 'tick' ? ($r->answers[$q['key']] ? __('Yes') : __('No')) : ($r->answers[$q['key']] ?: '—') }}</dd></div>
                    @endforeach
                </dl>
            @endif
            @if ($groups->isNotEmpty())
                <fieldset class="reader-list__groups">
                    <legend class="field__label">{{ __('Groups') }}</legend>
                    @foreach ($groups as $g)
                        @php $m = $chosenGroups->get($g->id); @endphp
                        <label class="open-to__choice">
                            <input type="checkbox" @checked($m && $m->state === 'in') wire:click="toggleMembership({{ $r->id }}, {{ $g->id }})">
                            {{ $g->name }} @if ($m && $m->state === 'asked') <span class="muted">{{ __('(asked)') }}</span> @endif
                        </label>
                    @endforeach
                </fieldset>
            @endif
            <div class="drawer__actions">
                @if ($r->state === 'waiting') <button type="button" class="btn btn--ink" wire:click="letIn({{ $r->id }})">{{ __('Let them in') }}</button> @endif
                @if ($r->state === 'unconfirmed')
                    <button type="button" class="btn" wire:click="confirmAgain({{ $r->id }})">{{ __('Send the confirm email again') }}</button>
                    <button type="button" class="btn btn--quiet" wire:click="letIn({{ $r->id }})">{{ __('Let them in anyway') }}</button>
                @endif
                @if ($r->state === 'blocked')
                    <button type="button" class="btn" wire:click="unblock({{ $r->id }})">{{ __('Unblock') }}</button>
                @else
                    <button type="button" class="btn btn--quiet" wire:click="block({{ $r->id }})" wire:confirm="{{ __('Block :name? They can’t sign in until you unblock them.', ['name' => $r->name]) }}">{{ __('Block') }}</button>
                @endif
                <button type="button" class="btn btn--danger" wire:click="remove({{ $r->id }})" wire:confirm="{{ __('Remove :name’s account? This can’t be undone.', ['name' => $r->name]) }}">{{ __('Remove') }}</button>
            </div>
        </x-admin.drawer>
    @endif
</div>
