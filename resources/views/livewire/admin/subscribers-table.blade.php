@php
    $pageIds = $people->getCollection()->pluck('id')->all();
    $mailState = \App\Support\MailSettings::status();
    $isAdmin = request()->user()->isAdmin();
@endphp
<div style="display: contents">
    {{-- Subscribers in the Dashboard layout: the switch and numbers, what's going
         out, everyone in a table, and what was sent. --}}
    <section class="page page--single page--dash" aria-labelledby="subscribers-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="subscribers-title">{{ __('Subscribers') }}</h1>
                    <p>{{ __('Readers who asked for new posts by email. They confirm from their inbox; each email has a one-click way out.') }}</p>
                </div>
                <a href="{{ route('admin.subscribers.export') }}" class="btn">{{ __('Download as CSV') }}</a>
                <button type="button" class="btn btn--ink" wire:click="$toggle('inviting')" aria-expanded="{{ $inviting ? 'true' : 'false' }}"><x-admin.icon name="plus" size="16" /> {{ __('Invite') }}</button>
            </div>

            @if ($mailState['state'] === 'off')
                <p class="notice notice--error" style="margin: 0">{{ __('Emails aren’t being sent yet: they’re only written to the log.') }}
                    @if ($isAdmin) <a href="{{ route('admin.settings.email') }}" wire:navigate>{{ __('Set up email →') }}</a> @else {{ __('Ask an admin to set up email in Settings.') }} @endif</p>
            @endif

            <div class="dash-figures">
                <div class="dash-figure dash-figure--switch">
                    <span class="dash-figure__label" id="newsletter-switch">{{ __('Email new posts') }}</span>
                    <span class="dash-figure__n"><button type="button" class="switch" role="switch" aria-labelledby="newsletter-switch" aria-checked="{{ $on ? 'true' : 'false' }}" wire:click="toggle"></button> <span>{{ $on ? __('On') : __('Paused') }}</span></span>
                </div>
                @foreach (['subscribed' => ['Subscribed', 'mint'], 'waiting' => ['Not confirmed yet', 'sun'], 'unsubscribed' => ['Left', 'sand']] as $key => [$label, $tone])
                    <button type="button" class="dash-figure" style="--tone: var(--{{ $tone }}); text-align: left; font: inherit; cursor: pointer" wire:click="$set('show', '{{ $key }}')">
                        <span class="dash-figure__label"><i aria-hidden="true"></i>{{ __($label) }}</span>
                        <span class="dash-figure__n"><b>{{ number_format($counts[$key]) }}</b></span>
                    </button>
                @endforeach
            </div>

            @if ($inviting)
                <form wire:submit="invite" class="dash-card dash-invite" aria-label="{{ __('Invite someone') }}">
                    <div class="field" style="grid-column: span 2">
                        <label for="invite-email">{{ __('Their email address') }}</label>
                        <input id="invite-email" type="email" class="input" wire:model="inviteEmail" placeholder="reader@example.com">
                        @error('inviteEmail') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="dash-invite__go">
                        <button type="submit" class="btn btn--ink">{{ __('Send an invitation') }}</button>
                        <button type="button" class="btn btn--quiet" wire:click="$set('inviting', false)">{{ __('Cancel') }}</button>
                    </div>
                    <p class="field__hint" style="grid-column: 1 / -1; margin: 0">{{ __('They get the same “confirm” email as signing up on the site, so nobody is added without saying yes.') }}</p>
                </form>
            @endif

            @if ($on && $waitingPosts->isNotEmpty())
                <section class="dash-card" aria-labelledby="due-title">
                    <div class="dash-card__head"><h2 id="due-title">{{ __('Going out soon') }}</h2></div>
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <tbody>
                                @foreach ($waitingPosts as $post)
                                    <tr wire:key="due-{{ $post->id() }}">
                                        <td><span class="dash-table__name">{{ $post->title() ?: __('Untitled') }}</span></td>
                                        <td class="is-quiet is-wide">{{ __('Live :when', ['when' => $post->date()?->diffForHumans()]) }}</td>
                                        <td class="dash-table__actions">
                                            <button type="button" class="btn btn--sm btn--ink" wire:click="sendNow(@js($post->id()))" wire:loading.attr="disabled">{{ __('Send now') }}</button>
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="skip(@js($post->id()))">{{ __('Don’t email this one') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="dash-card" aria-label="{{ __('Subscribers') }}">
                <div class="dash-tabs" role="group" aria-label="{{ __('Show') }}">
                    @foreach (['subscribed' => 'Subscribed', 'waiting' => 'Not confirmed', 'unsubscribed' => 'Left'] as $value => $label)
                        <button type="button" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}">{{ __($label) }} <b>{{ number_format($counts[$value]) }}</b></button>
                    @endforeach
                </div>
                @if ($ticked === [])
                    <div class="dash-tools">
                        <div class="search">
                            <x-admin.icon name="search" size="16" />
                            <label for="subscriber-search" class="sr-only">{{ __('Find by email') }}</label>
                            <input id="subscriber-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find by email') }}">
                        </div>
                    </div>
                @else
                    <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                        <strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
                        <button type="button" class="btn btn--danger btn--sm" wire:click="removeTicked" wire:confirm="{{ trans_choice('Remove :count subscriber and forget the address?|Remove :count subscribers and forget their addresses?', count($ticked)) }}">{{ __('Remove') }}</button>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('ticked', [])">{{ __('Clear') }}</button>
                    </div>
                @endif

                @if ($people->isEmpty())
                    <p class="dash-empty">{{ $search !== '' ? __('Nobody with that address.') : ($show === 'subscribed' ? __('No subscribers yet. The sign-up is at the bottom of every post.') : __('Nobody here.')) }}</p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="dash-table__tick"><input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked($pageIds !== [] && array_diff($pageIds, $ticked) === []) aria-label="{{ __('Choose all on this page') }}"></th>
                                    <th scope="col">{{ __('Email') }}</th>
                                    <th scope="col">{{ __('Status') }}</th>
                                    <th scope="col" class="is-wide">{{ $show === 'unsubscribed' ? __('Left') : ($show === 'waiting' ? __('Asked') : __('Since')) }}</th>
                                    <th scope="col" class="is-wide is-num">{{ __('Emails') }}</th>
                                    <th scope="col" class="is-wide">{{ __('How they came') }}</th>
                                    <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($people as $person)
                                    @php $st = $person->status(); @endphp
                                    <tr wire:key="sub-{{ $person->id }}" @class(['is-ticked' => in_array($person->id, $ticked, true)])>
                                        <td class="dash-table__tick"><input type="checkbox" wire:model.live="ticked" value="{{ $person->id }}" aria-label="{{ __('Choose :email', ['email' => $person->email]) }}"></td>
                                        <td class="dash-table__main"><span class="dash-table__name" style="font-weight: 500">{{ $person->email }}</span></td>
                                        <td><span class="dash-status dash-status--{{ ['subscribed' => 'published', 'waiting' => 'review'][$st] ?? 'draft' }}"><i aria-hidden="true"></i>{{ __(['subscribed' => 'Subscribed', 'waiting' => 'Not confirmed', 'unsubscribed' => 'Left'][$st] ?? $st) }}</span></td>
                                        <td class="is-wide is-quiet">
                                            @if ($st === 'subscribed') {{ $person->confirmed_at?->translatedFormat('j M Y') }}
                                            @elseif ($st === 'waiting') {{ $person->updated_at->diffForHumans() }}
                                            @else {{ $person->unsubscribed_at?->translatedFormat('j M Y') }} @endif
                                        </td>
                                        <td class="is-wide is-num">{{ number_format((int) $person->emails_sent) }}</td>
                                        <td class="is-wide is-quiet">{{ $person->source === 'invited' ? __('Invited') : __('Signed up') }}</td>
                                        <td class="dash-table__actions">
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="remove({{ $person->id }})" wire:confirm="{{ __('Remove :email and forget the address?', ['email' => $person->email]) }}">{{ __('Remove') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-admin.pager :of="$people" :sizes="$pageSizes" class="dash-foot" id="subscribers-pager" always />
                @endif
            </section>

            <section class="dash-card" aria-labelledby="sent-title">
                <div class="dash-card__head"><h2 id="sent-title">{{ __('Sent') }}</h2></div>
                @if ($sent->isEmpty())
                    <p class="dash-empty">{{ __('Nothing yet. The next post you publish will be the first.') }}</p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead><tr><th scope="col">{{ __('Post') }}</th><th scope="col" class="is-num">{{ __('Readers') }}</th><th scope="col" class="is-wide">{{ __('Sent') }}</th></tr></thead>
                            <tbody>
                                @foreach ($sent as $mail)
                                    <tr wire:key="sent-{{ $mail->id }}">
                                        <td><span class="dash-table__name" style="font-weight: 500">{{ $mail->title ?: __('Untitled') }}</span></td>
                                        <td class="is-num">{{ $mail->status === 'skipped' ? '—' : number_format((int) $mail->recipients) }}</td>
                                        <td class="is-wide is-quiet">
                                            @if ($mail->status === 'skipped') {{ __('Not emailed') }}
                                            @elseif ($mail->status === 'sending') {{ __('Sending…') }}
                                            @else {{ $mail->sent_at?->translatedFormat('j M Y, g:ia') }} @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </section>
</div>
