@php
    use App\Support\Inbox as Letters;
    $tones = ['var(--sun)', 'var(--sky)', 'var(--mint)', 'var(--lilac)', 'var(--coral)', 'var(--sand)'];
    $toneFor = fn (string $id) => $tones[crc32($id) % count($tones)];
    $pageIds = $pile->getCollection()->map(fn ($m) => $m->id())->all();
@endphp
<div style="display: contents">
    {{-- The Inbox in the Dashboard layout: messages in a table (unread in bold),
         ticks for many at once, and a drawer to read and write back. --}}
    <section class="page page--single page--dash" aria-labelledby="inbox-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="inbox-title">{{ __('Inbox') }}</h1>
                    <p>{!! __('Messages readers send from the form on your :contact, and answers to :forms.', [
                        'contact' => $contact ? '<a href="'.e(app(\App\Booklet\Site::class)->url($contact)).'" target="_blank" rel="noopener">'.e(__('Contact page ↗')).'</a>' : e(__('Contact page')),
                        'forms' => '<a href="'.e(route('admin.forms')).'" wire:navigate>'.e(__('your own forms')).'</a>',
                    ]) !!}</p>
                </div>
                <label class="dash-head__switch">
                    <span id="inbox-open">{{ __('Show the contact form') }}</span>
                    <button type="button" class="switch" role="switch" aria-labelledby="inbox-open" aria-checked="{{ $open ? 'true' : 'false' }}" wire:click="toggleOpen" @if (! $contact) title="{{ __('There’s no published page with the address /contact, so the form has nowhere to show.') }}" @endif></button>
                </label>
            </div>

            <section class="dash-card" aria-label="{{ __('Messages') }}">
                <div class="dash-tabs" role="group" aria-label="{{ __('Which messages') }}">
                    @foreach (['inbox' => 'Inbox', 'archived' => 'Archived', 'spam' => 'Spam'] as $value => $label)
                        <button type="button" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}">{{ __($label) }} <b>{{ number_format($counts[$value]) }}</b></button>
                    @endforeach
                </div>

                @if ($ticked === [])
                    <div class="dash-tools">
                        <div class="search">
                            <x-admin.icon name="search" size="16" />
                            <label for="inbox-search" class="sr-only">{{ __('Find a message') }}</label>
                            <input id="inbox-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('Find by name, email or words') }}">
                        </div>
                        @if (count($forms) > 1)
                            <label for="inbox-from" class="sr-only">{{ __('From') }}</label>
                            <select id="inbox-from" class="input" style="width: auto" wire:model.live="from">
                                <option value="">{{ __('Every form') }}</option>
                                @foreach ($forms as $handle => $name) <option value="{{ $handle }}">{{ __($name) }}</option> @endforeach
                            </select>
                            @if ($from !== '' && $from !== Letters::FORM)
                                <span class="dash-tools__links"><a href="{{ route('admin.forms.csv', $from) }}">{{ __('Download its answers (CSV)') }}</a> <a href="{{ route('admin.forms', ['form' => $from]) }}" wire:navigate>{{ __('Change the form →') }}</a></span>
                            @endif
                        @endif
                    </div>
                @else
                    <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                        <strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
                        @if ($show === 'inbox')
                            <button type="button" class="btn btn--sm" wire:click="doTicked('archived')">{{ __('Archive') }}</button>
                            <button type="button" class="btn btn--sm" wire:click="doTicked('read')">{{ __('Mark read') }}</button>
                            <button type="button" class="btn btn--sm" wire:click="doTicked('spam')">{{ __('Spam') }}</button>
                        @else
                            <button type="button" class="btn btn--sm" wire:click="doTicked('read')">{{ $show === 'spam' ? __('Not spam') : __('Back to the inbox') }}</button>
                        @endif
                        <button type="button" class="btn btn--danger btn--sm" wire:click="doTicked('delete')" wire:confirm="{{ trans_choice('Delete :count message for good?|Delete :count messages for good?', count($ticked)) }}">{{ __('Delete') }}</button>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('ticked', [])">{{ __('Clear') }}</button>
                    </div>
                @endif

                @if ($pile->isEmpty())
                    <p class="dash-empty">
                        @if ($search !== '') {{ __('Nothing matches.') }}
                        @elseif ($show === 'inbox') {{ __('No messages. When someone writes from the Contact page or fills in one of your forms, it lands here and you get an email.') }}
                        @elseif ($show === 'spam') {{ __('No spam. Messages that look like it wait here for :days days, then go.', ['days' => Letters::SPAM_DAYS]) }}
                        @else {{ __('Nothing archived.') }} @endif
                    </p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table dash-table--comments">
                            <thead>
                                <tr>
                                    <th scope="col" class="dash-table__tick"><input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked($pageIds !== [] && array_diff($pageIds, $ticked) === []) aria-label="{{ __('Choose all on this page') }}"></th>
                                    <th scope="col">{{ __('From') }}</th>
                                    <th scope="col">{{ __('Message') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Form') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Received') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pile as $m)
                                    @php [$who, $email] = Letters::sender($m); $isNew = Letters::status($m) === 'new'; @endphp
                                    <tr wire:key="msg-{{ $m->id() }}" @class(['is-ticked' => in_array($m->id(), $ticked, true), 'is-open' => $chosen?->id() === $m->id(), 'is-unread' => $isNew])>
                                        <td class="dash-table__tick"><input type="checkbox" wire:model.live="ticked" value="{{ $m->id() }}" aria-label="{{ __('Choose the message from :name', ['name' => $who ?: __('someone')]) }}"></td>
                                        <td class="dash-table__who">
                                            <span class="dash-person">
                                                <span class="avatar" style="--size: 30px; --tone: {{ $toneFor($m->id()) }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($who ?: '?', 0, 1)) }}</span>
                                                <span style="min-width: 0">
                                                    <span class="dash-table__name">@if ($isNew)<span class="sr-only">{{ __('Unread:') }} </span>@endif{{ $who ?: __('Someone') }}</span>
                                                    @if ($email)<span class="dash-table__sub">{{ $email }}</span>@endif
                                                </span>
                                            </span>
                                        </td>
                                        <td class="dash-table__main">
                                            <button type="button" class="dash-table__open" wire:click="pick('{{ $m->id() }}')">
                                                <span class="dash-table__quote">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', Letters::preview($m)), 140) }}</span>
                                            </button>
                                        </td>
                                        <td class="is-wide dash-table__on">{{ Letters::formName($m) }}</td>
                                        <td class="is-wide is-quiet" title="{{ Letters::at($m)?->translatedFormat('j M Y, g:i A') }}">{{ Letters::at($m)?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-admin.pager :of="$pile" :sizes="$pageSizes" class="dash-foot" id="inbox-pager" always />
                @endif
            </section>
        </div>
    </section>

    @if ($chosen)
        @php [$who, $email] = Letters::sender($chosen); $state = Letters::status($chosen); @endphp
        <x-admin.drawer :title="$who ?: __('A message')">
            <p class="drawer__meta">
                @if ($chosen->form() !== Letters::FORM) <span class="dash-tag">{{ Letters::formName($chosen) }}</span> @endif
                {{ Letters::at($chosen)?->translatedFormat('l j F Y, g:i a') }}
                @if ($chosen->get('_page')) · {{ __('from') }} <code>{{ $chosen->get('_page') }}</code> @endif
            </p>
            <div class="drawer-note">
                <div class="drawer-note__who">
                    <span class="avatar" style="--size: 40px; --tone: {{ $toneFor($chosen->id()) }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($who ?: '?', 0, 1)) }}</span>
                    <span style="min-width: 0">
                        <strong>{{ $who }}</strong>
                        @if ($email)<small><a href="mailto:{{ $email }}">{{ $email }}</a></small>@endif
                    </span>
                </div>
                @foreach (Letters::extras($chosen) as $label => $value)
                    <p class="drawer-note__extra"><strong>{{ preg_match('/[?:.!]$/u', $label) ? $label : $label.':' }}</strong> {{ $value }}</p>
                @endforeach
                @if (trim(Letters::body($chosen)) !== '') <p class="drawer-note__body">{{ Letters::body($chosen) }}</p> @endif
            </div>

            @if ($replies->isNotEmpty())
                <ol class="drawer-thread" role="list">
                    @foreach ($replies as $r)
                        <li>
                            <p><strong>{{ $r['by'] }}</strong> {{ __('wrote back') }} <span class="muted">· {{ $r['at']?->diffForHumans() }}</span></p>
                            <p class="drawer-thread__body">{{ $r['body'] }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif

            <div class="drawer__actions">
                @if ($state === 'spam')
                    <button type="button" class="btn btn--ink" wire:click="setChosenStatus('read')">{{ __('Not spam') }}</button>
                @elseif ($state === 'archived')
                    <button type="button" class="btn" wire:click="setChosenStatus('read')">{{ __('Back to the inbox') }}</button>
                @else
                    <button type="button" class="btn" wire:click="setChosenStatus('archived')">{{ __('Archive') }}</button>
                    <button type="button" class="btn btn--quiet" wire:click="setChosenStatus('new')">{{ __('Mark unread') }}</button>
                    <button type="button" class="btn btn--quiet" wire:click="setChosenStatus('spam')">{{ __('Spam') }}</button>
                @endif
                <button type="button" class="btn btn--danger" wire:click="deleteChosen" wire:confirm="{{ __('Delete this message for good?') }}">{{ __('Delete') }}</button>
            </div>

            @if ($email && $state !== 'spam')
                <form wire:submit="sendReply" class="drawer__reply">
                    <label for="inbox-reply" class="field__label">{{ __('Write back to :name', ['name' => \Illuminate\Support\Str::before($who, ' ')]) }}</label>
                    <textarea id="inbox-reply" rows="4" class="input" wire:model="reply" placeholder="{{ __('Sent by email from the book. Their answer comes to you, at :email', ['email' => request()->user()->email]) }}"></textarea>
                    @error('reply') <p class="field__error">{{ $message }}</p> @enderror
                    @if ($mailOff)
                        <p class="field__hint" style="margin: 0">{{ __('The book isn’t sending email yet, so this would only be written to the log.') }}
                            @if (request()->user()->isAdmin()) <a href="{{ route('admin.settings.email') }}" wire:navigate>{{ __('Set up email →') }}</a> @endif</p>
                    @endif
                    <div style="display: flex; gap: 8px; flex-wrap: wrap">
                        <button type="submit" class="btn btn--ink" wire:loading.attr="disabled" wire:target="sendReply">
                            <span wire:loading.remove wire:target="sendReply">{{ __('Send') }}</span>
                            <span wire:loading wire:target="sendReply">{{ __('Sending…') }}</span>
                        </button>
                        <a class="btn btn--quiet" href="mailto:{{ $email }}?subject={{ rawurlencode(__('Re: your message to :site', ['site' => app(\App\Booklet\Site::class)->name()])) }}">{{ __('Use my email app') }}</a>
                    </div>
                </form>
            @endif
        </x-admin.drawer>
    @endif
</div>
