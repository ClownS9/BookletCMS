<div style="display: contents">
    {{-- Left page: the switch, the numbers, what's going out --}}
    <section class="page page--left page--stack" style="flex-basis: 400px">
        <div>
            <a href="{{ route('admin.people') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> People</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">Subscribers</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">Readers who asked for new posts by email. They sign up at the bottom of any post and confirm from their inbox; each email has a one-click way out.</p>
        </div>

        <div class="setting-rows">
            <div class="setting-row">
                <span>
                    <span id="newsletter-switch" style="display: block; font-size: 15px; font-weight: 500">Email new posts</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">About {{ \App\Support\Newsletter::GRACE_MINUTES }} minutes after a post goes live (scheduled ones too). Pages and translations aren’t sent.</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="newsletter-switch" aria-checked="{{ $on ? 'true' : 'false' }}" wire:click="toggle"></button>
            </div>
        </div>

        <dl class="import-counts">
            <div><dt>Subscribed</dt><dd>{{ number_format($counts['subscribed']) }}</dd></div>
            <div><dt>Not confirmed yet</dt><dd>{{ number_format($counts['waiting']) }}</dd></div>
            <div><dt>Left</dt><dd>{{ number_format($counts['unsubscribed']) }}</dd></div>
        </dl>

        @if ($on && $waitingPosts->isNotEmpty())
            <div>
                <h2 class="eyebrow section-label">Going out soon</h2>
                <ul class="mail-list">
                    @foreach ($waitingPosts as $post)
                        <li wire:key="due-{{ $post->id() }}">
                            <span class="mail-list__title">{{ $post->title() ?: 'Untitled' }}</span>
                            <span class="mail-list__meta">Live {{ $post->date()?->diffForHumans() }}</span>
                            <span class="mail-list__do">
                                <button type="button" class="link-button" wire:click="sendNow(@js($post->id()))" wire:loading.attr="disabled">Send now</button>
                                <button type="button" class="link-button" wire:click="skip(@js($post->id()))">Don’t email this one</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <h2 class="eyebrow section-label">Sent</h2>
            @if ($sent->isEmpty())
                <p class="field__hint">Nothing yet. The next post you publish will be the first.</p>
            @else
                <ul class="mail-list">
                    @foreach ($sent as $mail)
                        <li wire:key="sent-{{ $mail->id }}">
                            <span class="mail-list__title">{{ $mail->title ?: 'Untitled' }}</span>
                            <span class="mail-list__meta">
                                @if ($mail->status === 'skipped')
                                    Not emailed
                                @elseif ($mail->status === 'sending')
                                    Sending…
                                @else
                                    {{ number_format($mail->recipients) }} {{ str('reader')->plural($mail->recipients) }} · {{ $mail->sent_at?->format('j M, g:ia') }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @php $mail = \App\Support\MailSettings::status(); @endphp
        @if ($mail['state'] === 'off')
            <p class="notice notice--error">Emails aren’t being sent yet: they’re only written to the log.
                @if (request()->user()->isAdmin()) <a href="{{ route('admin.settings.email') }}" wire:navigate>Set up email →</a> @else Ask an admin to set up email in Settings. @endif</p>
        @endif
        <p class="field__hint">Emails go through the book’s @if (request()->user()->isAdmin())<a href="{{ route('admin.settings.email') }}" wire:navigate>email settings</a>@else email settings @endif. For hundreds of readers, a sending service (Postmark, Mailgun, Amazon SES…) keeps them out of spam folders.</p>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: who --}}
    <section class="page page--right page--stack" style="--ribbon: var(--lilac)">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap">
            <div class="filters" role="group" aria-label="Show" style="margin: 0">
                @foreach (['subscribed' => 'Subscribed', 'waiting' => 'Not confirmed', 'unsubscribed' => 'Left'] as $value => $label)
                    <button type="button" class="pill" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <a href="{{ route('admin.subscribers.export') }}" class="btn btn--sm btn--quiet" style="margin-left: auto">Download as CSV</a>
        </div>

        <div class="search">
            <x-admin.icon name="search" size="16" />
            <label for="subscriber-search" class="sr-only">Find by email</label>
            <input id="subscriber-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Find by email">
        </div>

        @if ($people->isEmpty())
            <p class="empty-note">{{ $search !== '' ? 'Nobody with that address.' : ($show === 'subscribed' ? 'No subscribers yet. The sign-up is at the bottom of every post.' : 'Nobody here.') }}</p>
        @else
            <ul class="subscriber-list">
                @foreach ($people as $person)
                    <li wire:key="sub-{{ $person->id }}">
                        <span class="subscriber-list__email">{{ $person->email }}</span>
                        <span class="subscriber-list__meta">
                            @if ($person->status() === 'subscribed')
                                Since {{ $person->confirmed_at->format('j M Y') }} · {{ $person->emails_sent }} {{ str('email')->plural($person->emails_sent) }}
                            @elseif ($person->status() === 'waiting')
                                Asked {{ $person->updated_at->diffForHumans() }}{{ $person->source === 'invited' ? ' (invited)' : '' }}
                            @else
                                Left {{ $person->unsubscribed_at->format('j M Y') }}
                            @endif
                        </span>
                        <button type="button" class="link-button subscriber-list__remove" wire:click="remove({{ $person->id }})"
                                wire:confirm="Remove {{ $person->email }} and forget the address?">Remove</button>
                    </li>
                @endforeach
            </ul>
            <div style="display: flex; gap: 8px">
                @if (! $people->onFirstPage()) <button type="button" class="btn btn--sm" wire:click="previousPage">← Newer</button> @endif
                @if ($people->hasMorePages()) <button type="button" class="btn btn--sm" wire:click="nextPage">Older →</button> @endif
            </div>
        @endif

        <form wire:submit="invite" class="subscriber-invite">
            <label for="invite-email" class="field__label">Invite someone</label>
            <div style="display: flex; gap: 8px; flex-wrap: wrap">
                <input id="invite-email" type="email" class="input" wire:model="inviteEmail" placeholder="reader@example.com" style="flex: 1 1 220px">
                <button type="submit" class="btn btn--sm">Send an invitation</button>
            </div>
            @error('inviteEmail') <p class="field__error">{{ $message }}</p> @enderror
            <p class="field__hint">They get the same “confirm” email as signing up on the site, so nobody is added without saying yes.</p>
        </form>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
