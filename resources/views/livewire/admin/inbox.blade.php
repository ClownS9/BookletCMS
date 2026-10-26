@php
    use App\Support\Inbox as Letters;
    $tones = ['var(--sun)', 'var(--sky)', 'var(--mint)', 'var(--lilac)', 'var(--coral)', 'var(--sand)'];
    $toneFor = fn (string $id) => $tones[crc32($id) % count($tones)];
@endphp

<div style="display: contents">
    {{-- Left page: the letters --}}
    <section class="page page--left" style="flex-basis: 420px">
        <a href="{{ route('admin.comments') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Comments</a>
        <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · letters to the book</p>
        <h1 class="display display--page" style="margin-top: 8px">Inbox</h1>
        <p class="lede" style="margin: 12px 0 0; font-size: 15px">Messages readers send from the form on your
            @if ($contact) <a href="{{ app(\App\Booklet\Site::class)->url($contact) }}" target="_blank" rel="noopener">Contact page ↗</a>@else Contact page @endif,
            and answers to <a href="{{ route('admin.forms') }}" wire:navigate>your own forms</a>.</p>

        <div class="setting-rows" style="margin-top: 16px">
            <div class="setting-row">
                <span>
                    <span id="inbox-open" style="display: block; font-size: 15px; font-weight: 500">Show the contact form</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">
                        @if (! $contact) There’s no published page with the address /contact, so the form has nowhere to show.
                        @else On the Contact page, under its words. @endif
                    </span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="inbox-open" aria-checked="{{ $open ? 'true' : 'false' }}" wire:click="toggleOpen"></button>
            </div>
        </div>

        @if (count($forms) > 1)
            <div class="field inbox-from" style="margin: 18px 0 0">
                <label for="inbox-from">From</label>
                <select id="inbox-from" class="input" wire:model.live="from">
                    <option value="">Every form</option>
                    @foreach ($forms as $handle => $name) <option value="{{ $handle }}">{{ $name }}</option> @endforeach
                </select>
                @if ($from !== '' && $from !== Letters::FORM)
                    <p class="field__hint" style="margin: 4px 0 0"><a href="{{ route('admin.forms.csv', $from) }}">Download its answers (CSV)</a> · <a href="{{ route('admin.forms', ['form' => $from]) }}" wire:navigate>Change the form →</a></p>
                @endif
            </div>
        @endif

        <div class="filters" role="group" aria-label="Which messages" style="margin: 18px 0 10px">
            @foreach (['inbox' => 'Inbox', 'archived' => 'Archived', 'spam' => 'Spam'] as $value => $label)
                <button type="button" class="pill" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}">
                    {{ $label }} · {{ $counts[$value] }}
                </button>
            @endforeach
        </div>

        @if ($counts['inbox'] + $counts['archived'] + $counts['spam'] > 5)
            <div class="field" style="margin-bottom: 8px">
                <label for="inbox-search" class="sr-only">Find a message</label>
                <input id="inbox-search" type="search" wire:model.live.debounce.250ms="search" placeholder="Find by name, email or words">
            </div>
        @endif

        @if ($pile->isEmpty())
            <p class="empty-note">
                @if ($search !== '') Nothing matches.
                @elseif ($show === 'inbox') No messages. When someone writes from the Contact page or fills in one of your forms, it lands here and you get an email.
                @elseif ($show === 'spam') No spam. Messages that look like it wait here for {{ Letters::SPAM_DAYS }} days, then go.
                @else Nothing archived. @endif
            </p>
        @else
            <ul class="picks">
                @foreach ($pile as $m)
                    @php [$from, $email] = Letters::sender($m); $isNew = Letters::status($m) === 'new'; @endphp
                    <li wire:key="msg-{{ $m->id() }}">
                        <button type="button" @class(['pick', 'pick--unread' => $isNew]) wire:click="pick('{{ $m->id() }}')" aria-pressed="{{ $chosen?->id() === $m->id() ? 'true' : 'false' }}">
                            <span class="avatar" style="--size: 36px; --tone: {{ $toneFor($m->id()) }}">{{ mb_strtoupper(mb_substr($from, 0, 1)) }}</span>
                            <span style="flex: 1 1 auto; min-width: 0">
                                <span class="inbox-pick__top">
                                    <span class="inbox-pick__from">@if ($isNew)<span class="sr-only">Unread: </span>@endif{{ $from }}</span>
                                    <span class="inbox-pick__when">{{ Letters::at($m)?->diffForHumans(short: true) }}</span>
                                </span>
                                @if ($from === '' && $m->form() !== Letters::FORM) <span class="inbox-pick__form">{{ Letters::formName($m) }}</span> @endif
                                <span class="serif inbox-pick__text">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', Letters::preview($m)), 140) }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: one letter --}}
    <section class="page page--right" style="--ribbon: var(--rose)">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($chosen)
            @php [$from, $email] = Letters::sender($chosen); $state = Letters::status($chosen); @endphp
            <p class="ribbon-clear" style="font-size: 14px; color: var(--faint)">
                @if ($chosen->form() !== Letters::FORM) <span class="inbox-pick__form">{{ Letters::formName($chosen) }}</span> · @endif
                {{ Letters::at($chosen)?->format('l j F Y, g:i a') }}
                @if ($chosen->get('_page')) · from <span style="font-family: var(--mono); font-size: 12.5px">{{ $chosen->get('_page') }}</span> @endif
            </p>

            <div class="sticky-note">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px">
                    <span class="avatar" style="--size: 44px; --tone: {{ $toneFor($chosen->id()) }}">{{ mb_strtoupper(mb_substr($from, 0, 1)) }}</span>
                    <span style="min-width: 0">
                        <span style="display: block; font-weight: 600">{{ $from }}</span>
                        @if ($email) <a href="mailto:{{ $email }}" style="display: block; font-size: 13px; color: #6B655A; overflow-wrap: anywhere">{{ $email }}</a> @endif
                    </span>
                    @if (in_array($state, ['archived', 'spam'], true))
                        <span class="sticker sticker--{{ $state === 'spam' ? 'sand' : 'sky' }}" style="margin-left: auto; --tilt: 3deg">{{ ucfirst($state) }}</span>
                    @endif
                </div>
                @foreach (Letters::extras($chosen) as $label => $value)
                    <p style="margin: 0 0 6px; font-size: 14px; color: #4A453C"><strong>{{ preg_match('/[?:.!]$/u', $label) ? $label : $label.':' }}</strong> {{ $value }}</p>
                @endforeach
                @if (trim(Letters::body($chosen)) !== '') <p class="serif inbox-letter">{{ Letters::body($chosen) }}</p> @endif
            </div>

            @if ($replies->isNotEmpty())
                <ol class="inbox-replies">
                    @foreach ($replies as $r)
                        <li>
                            <p style="font-size: 14px"><strong>{{ $r['by'] }}</strong> wrote back <span class="muted">· {{ $r['at']?->diffForHumans() }}</span></p>
                            <p class="serif" style="margin-top: 4px; font-size: 17px; line-height: 1.55; white-space: pre-line">{{ $r['body'] }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif

            <div style="margin-top: 24px; display: flex; gap: 10px; flex-wrap: wrap">
                @if ($state === 'spam')
                    <button type="button" class="btn btn--ink" wire:click="setChosenStatus('read')">Not spam</button>
                @elseif ($state === 'archived')
                    <button type="button" class="btn" wire:click="setChosenStatus('read')">Back to the inbox</button>
                @else
                    <button type="button" class="btn" wire:click="setChosenStatus('archived')">Archive</button>
                    <button type="button" class="btn btn--quiet" wire:click="setChosenStatus('new')">Mark unread</button>
                    <button type="button" class="btn btn--quiet" wire:click="setChosenStatus('spam')">Spam</button>
                @endif
                <button type="button" class="btn btn--danger" wire:click="deleteChosen" wire:confirm="Delete this message for good?">Delete</button>
            </div>

            @if ($email && $state !== 'spam')
                <form wire:submit="sendReply" style="margin-top: auto; padding-top: 28px">
                    <label for="inbox-reply" class="field__label" style="display: block; margin-bottom: 8px">Write back to {{ \Illuminate\Support\Str::before($from, ' ') }}</label>
                    <textarea id="inbox-reply" rows="4" class="lined" wire:model="reply" placeholder="Sent by email from the book. Their answer comes to you, at {{ request()->user()->email }}"></textarea>
                    @error('reply') <p class="field__error">{{ $message }}</p> @enderror
                    @if ($mailOff)
                        <p class="field__hint" style="margin-top: 8px">The book isn’t sending email yet, so this would only be written to the log.
                            @if (request()->user()->isAdmin()) <a href="{{ route('admin.settings.email') }}" wire:navigate>Set up email →</a> @endif</p>
                    @endif
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 14px; flex-wrap: wrap">
                        <span style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
                            <button type="submit" class="btn btn--ink" wire:loading.attr="disabled" wire:target="sendReply">
                                <span wire:loading.remove wire:target="sendReply">Send</span>
                                <span wire:loading wire:target="sendReply">Sending…</span>
                            </button>
                            <a class="btn btn--quiet" href="mailto:{{ $email }}?subject={{ rawurlencode('Re: your message to '.app(\App\Booklet\Site::class)->name()) }}">Use my email app</a>
                        </span>
                        <x-admin.folio side="right" style="margin: 0; padding: 0" />
                    </div>
                </form>
            @else
                <x-admin.folio side="right" />
            @endif
        @else
            <p class="empty-note" style="max-width: 460px">Pick a message on the left to read it here.</p>
            <x-admin.folio side="right" />
        @endif
    </section>
</div>
