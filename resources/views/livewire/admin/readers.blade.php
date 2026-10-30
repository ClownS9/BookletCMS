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
                    <div style="display: flex; gap: 8px">
                        @if (! $readers->onFirstPage()) <button type="button" class="btn btn--sm" wire:click="previousPage">{{ __('← Newer') }}</button> @endif
                        @if ($readers->hasMorePages()) <button type="button" class="btn btn--sm" wire:click="nextPage">{{ __('Older →') }}</button> @endif
                    </div>
                @endif

            @elseif ($tab === 'groups')
                <div class="ribbon-clear" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap">
                    <h2 class="heading" style="margin-right: auto">{{ $groupId === 0 ? __('A new group') : ($group ? $group->name : __('Groups')) }}</h2>
                    @if ($groupId !== null) <button type="button" class="btn btn--sm btn--quiet" wire:click="closeGroup">{{ __('All groups') }}</button>
                    @else <button type="button" class="btn btn--sm" wire:click="newGroup">{{ __('Make a group') }}</button> @endif
                </div>

                @if ($groupId === null)
                    @if ($groups->isEmpty())
                        <p class="empty-note">{{ __('No groups yet. A group gathers readers (and people from the book) so posts, pages and page sections can be opened to them: “Members”, “Kuih Club”, “Staff”.') }}</p>
                    @else
                        <ul class="lib-cards">
                            @foreach ($groups as $g)
                                <li wire:key="group-{{ $g->id }}">
                                    <button type="button" class="lib-card" style="--tone: var(--lilac)" wire:click="openGroup({{ $g->id }})">
                                        <span class="lib-card__name">{{ $g->name }}</span>
                                        <span class="lib-card__meta">{{ trans_choice(':count member|:count members', $g->in_count) }} · {{ __(\App\Models\Group::JOINING[$g->joining][0] ?? $g->joining) }}@if ($g->asked_count) · {{ trans_choice(':count asking|:count asking', $g->asked_count) }}@endif</span>
                                        @if ($g->about) <span class="lib-card__about">{{ $g->about }}</span> @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    <form wire:submit="saveGroup" class="reader-group-form">
                        <div class="field">
                            <label for="group-name">{{ __('Name') }}</label>
                            <input id="group-name" type="text" class="input" wire:model="groupName" maxlength="80" placeholder="{{ __('Members, Kuih Club, Staff…') }}">
                            @error('groupName') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label for="group-about">{{ __('What it’s for') }} <span class="muted">{{ __('(shown to readers who can join it)') }}</span></label>
                            <textarea id="group-about" class="input" rows="2" wire:model="groupAbout" maxlength="300"></textarea>
                        </div>
                        <fieldset class="field" style="border: 0; margin: 0; padding: 0">
                            <legend class="field__label" style="margin-bottom: 6px">{{ __('How people get in') }}</legend>
                            @foreach (\App\Models\Group::JOINING as $value => [$label, $hint])
                                <label class="open-to__choice open-to__choice--two">
                                    <input type="radio" wire:model.live="groupJoining" value="{{ $value }}">
                                    <span><b>{{ __($label) }}</b> <span class="muted">{{ __($hint) }}</span></span>
                                </label>
                            @endforeach
                        </fieldset>
                        @if ($group && $group->joining === 'invite' && $group->invite_token)
                            @php $link = app(\App\Booklet\Site::class)->absolute(route('readers.group-invite', [$group->handle, $group->invite_token])); @endphp
                            <div class="field" x-data="{ copied: false }">
                                <span class="field__label">{{ __('The invitation link') }}</span>
                                <div class="api-fresh__key">
                                    <code style="overflow-wrap: anywhere">{{ $link }}</code>
                                    <button type="button" class="btn btn--sm" x-on:click="navigator.clipboard?.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 1500)"><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button>
                                </div>
                                <button type="button" class="link-button" style="align-self: flex-start" wire:click="newInviteLink" wire:confirm="{{ __('Make a new link? The old one stops working.') }}">{{ __('Make a new link') }}</button>
                            </div>
                        @endif
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
                            <button type="submit" class="btn btn--ink">{{ $groupId === 0 ? __('Make the group') : __('Save') }}</button>
                            @if ($group) <button type="button" class="link-button reader-list__remove" wire:click="deleteGroup" wire:confirm="{{ __('Delete :group? Posts and pages open to it become open to any signed-in reader.', ['group' => $group->name]) }}">{{ __('Delete the group') }}</button> @endif
                        </div>
                    </form>

                    @if ($group)
                        <div>
                            <h3 class="eyebrow section-label">{{ __('Who’s in it') }}</h3>
                            <div class="search">
                                <x-admin.icon name="search" size="16" />
                                <label for="member-search" class="sr-only">{{ __('Add someone') }}</label>
                                <input id="member-search" type="search" wire:model.live.debounce.300ms="memberSearch" placeholder="{{ __('Add someone: a reader or someone from the book') }}">
                            </div>
                            @if ($found->isNotEmpty())
                                <ul class="reader-found">
                                    @foreach ($found as $f)
                                        <li wire:key="found-{{ $f['type'] }}-{{ $f['who']->id }}">
                                            <span><b>{{ $f['who']->name }}</b> <span class="muted">{{ $f['who']->email }} · {{ $f['type'] === 'user' ? __('from the book') : __('reader') }}</span></span>
                                            <button type="button" class="btn btn--sm" wire:click="addMember('{{ $f['type'] }}', {{ $f['who']->id }})">{{ __('Add') }}</button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (mb_strlen(trim($memberSearch)) >= 2)
                                <p class="field__hint">{{ __('Nobody by that name or email who isn’t in it already.') }}</p>
                            @endif

                            @if ($members->isEmpty())
                                <p class="empty-note">{{ __('Nobody in it yet.') }}</p>
                            @else
                                <ul class="subscriber-list">
                                    @foreach ($members as $row)
                                        <li wire:key="member-{{ $row['member']->id }}">
                                            <span class="subscriber-list__email">{{ $row['who']->name }} <span class="muted">· {{ $row['member']->member_type === 'user' ? __('from the book') : $row['who']->email }}</span></span>
                                            <span class="subscriber-list__meta">
                                                @if ($row['member']->state === 'asked')
                                                    {{ __('Asked :when', ['when' => $row['member']->created_at->diffForHumans()]) }} ·
                                                    <button type="button" class="link-button" style="min-height: 0; font-size: inherit" wire:click="answerAsk({{ $row['member']->id }}, true)">{{ __('Let them in') }}</button>
                                                @else
                                                    {{ __('In since :date', ['date' => $row['member']->created_at->translatedFormat('j M Y')]) }}
                                                @endif
                                            </span>
                                            <button type="button" class="link-button subscriber-list__remove" wire:click="removeMember({{ $row['member']->id }})">{{ $row['member']->state === 'asked' ? __('Say no') : __('Take out') }}</button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                @endif

            @elseif ($tab === 'joining' && $isAdmin)
                <form wire:submit="saveJoining" class="reader-joining">
                    <div class="ribbon-clear">
                        <h2 class="heading">{{ __('How people join') }}</h2>
                        <p class="field__hint" style="margin-top: 6px">{!! __('Readers’ pages are at :address (sign in, join, their account). A theme can give them its own look.', ['address' => '<code>'.e(\App\Support\Readers::path()).'</code>']) !!}</p>
                    </div>

                    <fieldset class="field" style="border: 0; margin: 0; padding: 0">
                        <legend class="field__label" style="margin-bottom: 6px">{{ __('Who can make an account') }}</legend>
                        @foreach (\App\Support\Readers::JOINING as $value => [$label, $hint])
                            <label class="open-to__choice open-to__choice--two">
                                <input type="radio" wire:model.live="settings.joining" value="{{ $value }}">
                                <span><b>{{ __($label) }}</b> <span class="muted">{{ __($hint) }}</span></span>
                            </label>
                        @endforeach
                    </fieldset>

                    <div class="setting-rows">
                        <div class="setting-row">
                            <span>
                                <span id="readers-link" style="display: block; font-size: 15px; font-weight: 500">{{ __('“Sign in” in the site’s top bar') }}</span>
                                <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Off: link to :address yourself, from a menu or a page.', ['address' => \App\Support\Readers::path('sign-in')]) }}</span>
                            </span>
                            <button type="button" class="switch" role="switch" aria-labelledby="readers-link" aria-checked="{{ ($settings['link'] ?? true) ? 'true' : 'false' }}" wire:click="$toggle('settings.link')"></button>
                        </div>
                    </div>

                    <div class="field">
                        <label for="readers-after">{{ __('After signing in, they land on') }} <span class="muted">{{ __('(optional)') }}</span></label>
                        <input id="readers-after" type="text" class="input" wire:model="settings.after" placeholder="{{ __('/welcome, or empty for their account page') }}" maxlength="200" autocapitalize="off" spellcheck="false">
                        @error('settings.after') <p class="field__error">{{ $message }}</p> @enderror
                        <p class="field__hint">{{ __('Someone who opened a members-only page goes back to it instead.') }}</p>
                    </div>

                    <div>
                        <h3 class="eyebrow section-label">{{ __('Questions on the sign-up page') }}</h3>
                        <p class="field__hint" style="margin: 0 0 10px">{{ __('Name, email and a password are always asked. Add up to :max more; answers show on each reader’s card.', ['max' => \App\Support\Readers::MAX_QUESTIONS]) }}</p>
                        @error('questions') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                        <ol class="reader-questions">
                            @foreach ($questions as $i => $q)
                                <li wire:key="q-{{ $i }}-{{ $q['key'] }}">
                                    <div class="reader-questions__row">
                                        <div class="field" style="flex: 1 1 220px">
                                            <label for="q-label-{{ $i }}">{{ __('The question') }}</label>
                                            <input id="q-label-{{ $i }}" type="text" class="input" wire:model="questions.{{ $i }}.label" maxlength="120" placeholder="{{ __('Where are you from?') }}">
                                            @error('questions.'.$i.'.label') <p class="field__error">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="field" style="flex: 0 1 170px">
                                            <label for="q-type-{{ $i }}">{{ __('Answered with') }}</label>
                                            <select id="q-type-{{ $i }}" class="input" wire:model.live="questions.{{ $i }}.type">
                                                @foreach (\App\Support\Readers::QUESTION_TYPES as $type => $label) <option value="{{ $type }}">{{ __($label) }}</option> @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    @if ($q['type'] === 'choice')
                                        <div class="field">
                                            <label for="q-options-{{ $i }}">{{ __('The choices, separated by commas') }}</label>
                                            <input id="q-options-{{ $i }}" type="text" class="input" wire:model="questions.{{ $i }}.options" maxlength="600" placeholder="{{ __('Brunei, Malaysia, Singapore, Somewhere else') }}">
                                        </div>
                                    @endif
                                    <div class="reader-questions__do">
                                        <label class="open-to__choice"><input type="checkbox" wire:model="questions.{{ $i }}.required"> {{ $q['type'] === 'tick' ? __('Must be ticked') : __('Must be answered') }}</label>
                                        <span style="margin-left: auto; display: flex; gap: 4px">
                                            <button type="button" class="icon-btn" wire:click="moveQuestion({{ $i }}, -1)" @disabled($loop->first) aria-label="{{ __('Move up') }}">↑</button>
                                            <button type="button" class="icon-btn" wire:click="moveQuestion({{ $i }}, 1)" @disabled($loop->last) aria-label="{{ __('Move down') }}">↓</button>
                                            <button type="button" class="link-button reader-list__remove" wire:click="removeQuestion({{ $i }})">{{ __('Remove') }}</button>
                                        </span>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                        @if (count($questions) < \App\Support\Readers::MAX_QUESTIONS)
                            <button type="button" class="btn btn--sm" wire:click="addQuestion">{{ __('Add a question') }}</button>
                        @endif
                    </div>

                    <details class="takedown" @if (array_filter([$settings['join_title'] ?? '', $settings['join_lede'] ?? '', $settings['signin_title'] ?? '', $settings['locked_lede'] ?? '', $settings['welcome'] ?? ''])) open @endif>
                        <summary><span>{{ __('The words on the pages') }}</span></summary>
                        <p class="field__hint" style="margin: 0 0 10px">{{ __('Leave any empty for Booklet’s own (in the site’s language).') }}</p>
                        <div class="field">
                            <label for="w-join-title">{{ __('Sign-up page: heading') }}</label>
                            <input id="w-join-title" type="text" class="input" wire:model="settings.join_title" maxlength="120" placeholder="{{ __('Join :site', ['site' => app(\App\Booklet\Site::class)->name()]) }}">
                        </div>
                        <div class="field">
                            <label for="w-join-lede">{{ __('Sign-up page: the line under it') }}</label>
                            <textarea id="w-join-lede" class="input" rows="2" wire:model="settings.join_lede" maxlength="400" placeholder="{{ __('An account lets you read what’s for members, and comment without typing your name each time.') }}"></textarea>
                        </div>
                        <div class="field">
                            <label for="w-signin-title">{{ __('Sign-in page: heading') }}</label>
                            <input id="w-signin-title" type="text" class="input" wire:model="settings.signin_title" maxlength="120" placeholder="{{ __('Sign in to :site', ['site' => app(\App\Booklet\Site::class)->name()]) }}">
                        </div>
                        <div class="field">
                            <label for="w-locked">{{ __('Members-only pages: what others see') }}</label>
                            <textarea id="w-locked" class="input" rows="2" wire:model="settings.locked_lede" maxlength="400" placeholder="{{ __('Sign in to read the rest, or make an account: it’s free.') }}"></textarea>
                        </div>
                        <div class="field">
                            <label for="w-welcome">{{ __('A line for the welcome email') }}</label>
                            <textarea id="w-welcome" class="input" rows="2" wire:model="settings.welcome" maxlength="600" placeholder="{{ __('New recipes go up every Friday.') }}"></textarea>
                        </div>
                    </details>

                    <button type="submit" class="btn btn--ink btn--lg" style="align-self: flex-start">{{ __('Save') }}</button>
                </form>
            @endif
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
