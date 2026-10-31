<div style="display: contents">
    {{-- Left page: what's waiting, the numbers, inviting --}}
    <section class="page page--left page--stack" style="flex-basis: 400px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.people') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('People') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Readers & groups') }}</h1>
                <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('People with an account on your site. They can read what’s open to members, but never this book: their accounts are kept apart from the people who write here.') }}</p>
            </div>
        </div>

        <x-admin.card>
            <div class="filters" role="tablist" aria-label="{{ __('Show') }}">
                <button type="button" class="pill" role="tab" wire:click="setTab('readers')" aria-selected="{{ $tab === 'readers' ? 'true' : 'false' }}" aria-pressed="{{ $tab === 'readers' ? 'true' : 'false' }}">{{ __('Readers') }}</button>
                <button type="button" class="pill" role="tab" wire:click="setTab('groups')" aria-selected="{{ $tab === 'groups' ? 'true' : 'false' }}" aria-pressed="{{ $tab === 'groups' ? 'true' : 'false' }}">{{ __('Groups') }}</button>
                @if ($isAdmin)
                    <button type="button" class="pill" role="tab" wire:click="setTab('joining')" aria-selected="{{ $tab === 'joining' ? 'true' : 'false' }}" aria-pressed="{{ $tab === 'joining' ? 'true' : 'false' }}">{{ __('How people join') }}</button>
                @endif
            </div>

            @if ($joiningNow === 'off' && $counts['active'] + $counts['waiting'] + $counts['unconfirmed'] === 0)
                <div class="vc-note">
                    <p><strong>{{ __('Nobody can join yet.') }}</strong> {{ $isAdmin ? __('Choose how people join, and “Sign in” appears on the site.') : __('An admin chooses how people join.') }}</p>
                    @if ($isAdmin) <button type="button" class="btn btn--sm" wire:click="setTab('joining')">{{ __('How people join') }}</button> @endif
                </div>
            @endif

            <dl class="import-counts">
                <div><dt>{{ __('Readers') }}</dt><dd>{{ number_format($counts['active']) }}</dd></div>
                <div><dt>{{ __('Waiting for you') }}</dt><dd>{{ number_format($counts['waiting']) }}</dd></div>
                <div><dt>{{ __('Not confirmed yet') }}</dt><dd>{{ number_format($counts['unconfirmed']) }}</dd></div>
            </dl>

            @if ($waiting->isNotEmpty() || $asks->isNotEmpty())
                <div>
                    <h2 class="eyebrow section-label">{{ __('Waiting for you') }}</h2>
                    <ul class="mail-list">
                        @foreach ($waiting as $r)
                            <li wire:key="wait-{{ $r->id }}">
                                <span class="mail-list__title">{{ $r->name }}</span>
                                <span class="mail-list__meta">{{ $r->email }} · {{ __('asked to join the site :when', ['when' => $r->created_at->diffForHumans()]) }}</span>
                                <span class="mail-list__do">
                                    <button type="button" class="link-button" wire:click="letIn({{ $r->id }})">{{ __('Let them in') }}</button>
                                    <button type="button" class="link-button" wire:click="turnAway({{ $r->id }})" wire:confirm="{{ __('Turn :name away? Their account is deleted.', ['name' => $r->name]) }}">{{ __('Turn away') }}</button>
                                </span>
                            </li>
                        @endforeach
                        @foreach ($asks as $row)
                            <li wire:key="ask-{{ $row['member']->id }}">
                                <span class="mail-list__title">{{ $row['who']->name }}</span>
                                <span class="mail-list__meta">{{ __('asked to join :group', ['group' => $row['member']->group?->name]) }} · {{ $row['member']->created_at->diffForHumans() }}</span>
                                <span class="mail-list__do">
                                    <button type="button" class="link-button" wire:click="answerAsk({{ $row['member']->id }}, true)">{{ __('Let them in') }}</button>
                                    <button type="button" class="link-button" wire:click="answerAsk({{ $row['member']->id }}, false)">{{ __('Say no') }}</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form wire:submit="invite" class="reader-invite">
                <h2 class="eyebrow section-label">{{ __('Invite someone') }}</h2>
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
                <button type="submit" class="btn btn--sm" style="align-self: flex-start">{{ __('Send an invitation') }}</button>
                <p class="field__hint">{{ __('A link to make their account, good for :days days. It works even when joining is by invitation only.', ['days' => \App\Support\ReaderAccounts::INVITE_DAYS]) }}</p>
            </form>
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: readers, a group, or how people join --}}
    <section class="page page--right page--stack" style="--ribbon: var(--lilac)">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            @if ($tab === 'readers')
                <div class="ribbon-clear">
                    <h2 class="heading">{{ __('Readers') }}</h2>
                    <div class="filters" role="group" aria-label="{{ __('Show') }}" style="margin-top: 12px">
                        @foreach (['all' => 'Everyone', 'active' => 'In', 'waiting' => 'Waiting', 'unconfirmed' => 'Not confirmed', 'blocked' => 'Blocked'] as $value => $label)
                            <button type="button" class="pill" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}">{{ __($label) }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="reader-tools">
                    <div class="search" style="flex: 1 1 220px">
                        <x-admin.icon name="search" size="16" />
                        <label for="reader-search" class="sr-only">{{ __('Find by name or email') }}</label>
                        <input id="reader-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find by name or email') }}">
                    </div>
                    @if ($groups->isNotEmpty())
                        <label for="reader-in-group" class="sr-only">{{ __('In a group') }}</label>
                        <select id="reader-in-group" wire:model.live="inGroup" class="reader-tools__group">
                            <option value="">{{ __('Any group') }}</option>
                            @foreach ($groups as $g) <option value="{{ $g->handle }}">{{ $g->name }}</option> @endforeach
                        </select>
                    @endif
                </div>

                @if ($readers->isEmpty())
                    <p class="empty-note">{{ $search !== '' || $show !== 'all' || $inGroup !== '' ? __('Nobody here.') : __('No readers yet. Once people can join, they appear here.') }}</p>
                @else
                    <ul class="reader-list">
                        @foreach ($readers as $r)
                            @php $mine = $memberGroups->get($r->id, collect())->keyBy('group_id'); @endphp
                            <li wire:key="reader-{{ $r->id }}" @class(['reader-list__item', 'is-open' => $open === $r->id])>
                                <button type="button" class="reader-list__row" wire:click="toggle({{ $r->id }})" aria-expanded="{{ $open === $r->id ? 'true' : 'false' }}">
                                    <span class="reader-list__name">{{ $r->name }}</span>
                                    <span class="reader-list__email">{{ $r->email }}</span>
                                    <span @class(['reader-state', 'reader-state--'.$r->state])>{{ __(['active' => 'In', 'waiting' => 'Waiting', 'unconfirmed' => 'Not confirmed', 'blocked' => 'Blocked'][$r->state] ?? $r->state) }}</span>
                                </button>
                                @if ($open === $r->id)
                                    <div class="reader-list__card">
                                        <p class="reader-list__meta">
                                            {{ __('Joined :date', ['date' => $r->created_at->translatedFormat('j M Y')]) }}
                                            @if ($r->last_seen_at) · {{ __('last signed in :when', ['when' => $r->last_seen_at->diffForHumans()]) }} @endif
                                        </p>
                                        @if (! empty($r->answers) && $questionList !== [])
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
                                                    @php $m = $mine->get($g->id); @endphp
                                                    <label class="open-to__choice">
                                                        <input type="checkbox" @checked($m && $m->state === 'in') wire:click="toggleMembership({{ $r->id }}, {{ $g->id }})">
                                                        {{ $g->name }} @if ($m && $m->state === 'asked') <span class="muted">{{ __('(asked)') }}</span> @endif
                                                    </label>
                                                @endforeach
                                            </fieldset>
                                        @endif
                                        <div class="reader-list__do">
                                            @if ($r->state === 'waiting') <button type="button" class="btn btn--sm btn--ink" wire:click="letIn({{ $r->id }})">{{ __('Let them in') }}</button> @endif
                                            @if ($r->state === 'unconfirmed')
                                                <button type="button" class="btn btn--sm" wire:click="confirmAgain({{ $r->id }})">{{ __('Send the confirm email again') }}</button>
                                                <button type="button" class="btn btn--sm btn--quiet" wire:click="letIn({{ $r->id }})">{{ __('Let them in anyway') }}</button>
                                            @endif
                                            @if ($r->state === 'blocked')
                                                <button type="button" class="btn btn--sm" wire:click="unblock({{ $r->id }})">{{ __('Unblock') }}</button>
                                            @else
                                                <button type="button" class="btn btn--sm btn--quiet" wire:click="block({{ $r->id }})" wire:confirm="{{ __('Block :name? They can’t sign in until you unblock them.', ['name' => $r->name]) }}">{{ __('Block') }}</button>
                                            @endif
                                            <button type="button" class="link-button reader-list__remove" wire:click="remove({{ $r->id }})" wire:confirm="{{ __('Remove :name’s account? This can’t be undone.', ['name' => $r->name]) }}">{{ __('Remove') }}</button>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <x-admin.pager :of="$readers" :sizes="$pageSizes" class="picks-pager" id="readers-pager" :previous="__('Newer')" :next="__('Older')" />
                @endif

            @else
                @include('livewire.admin.partials.readers-groups')
            @endif
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
