<div style="display: contents"
     x-data="{ copied: null, copy(text, id) { navigator.clipboard?.writeText(text).then(() => { this.copied = id; setTimeout(() => this.copied = null, 1800) }) } }">
    {{-- Left page: the webhooks, and new ones --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Webhooks') }}</h1>
                <p class="lede" style="margin-top: 12px; font-size: 15px">{{ __('When something happens in the book, call an address: a static host rebuilds, a Slack or Discord channel hears about it, Zapier or Make takes it from there.') }}</p>
            </div>
        </div>

        <x-admin.card>
            @if ($hooks->isEmpty())
                <p class="empty-note">{{ __('No webhooks yet. Start with what it’s for:') }}</p>
            @else
                <ul class="picks">
                    @foreach ($hooks as $h)
                        @php $tone = ! $h->active ? 'var(--sand)' : ($h->failures > 0 ? 'var(--coral)' : 'var(--mint)'); @endphp
                        <li wire:key="hook-{{ $h->id }}">
                            <button type="button" class="pick" wire:click="pick({{ $h->id }})" aria-pressed="{{ $chosen === (string) $h->id ? 'true' : 'false' }}">
                                <span class="dot" style="--dot: {{ $tone }}; --tilt: 0deg" aria-hidden="true"></span>
                                <span style="flex: 1 1 auto; min-width: 0">
                                    <span class="serif" style="display: block; font-size: 17px; overflow-wrap: anywhere">{{ $h->name }}</span>
                                    <span style="display: block; font-size: 13px; color: {{ $h->active && $h->failures ? 'var(--danger)' : 'var(--faint)' }}; overflow-wrap: anywhere">
                                        @if (! $h->active) {{ __('Switched off') }}
                                        @elseif ($h->failures) {{ trans_choice(':count try in a row didn’t go through|:count tries in a row didn’t go through', $h->failures) }}
                                        @elseif ($h->last_sent_at) {{ __('Last sent :when', ['when' => $h->last_sent_at->diffForHumans()]) }}
                                        @else {{ __('Not sent yet') }} @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="hook-starts" role="group" aria-label="{{ __('A new webhook') }}">
                <span class="field__label" style="margin: 0">{{ $hooks->isEmpty() ? '' : __('A new webhook') }}</span>
                @foreach ($starts as $key => [$label, $ev, $fmt, $hint])
                    <button type="button" class="hook-start" wire:click="start('{{ $key }}')">
                        <span class="hook-start__icon" aria-hidden="true">
                            @if ($key === 'rebuild') <x-admin.icon name="layers" size="16" />
                            @elseif ($key === 'zapier') <x-admin.icon name="code" size="16" />
                            @else <x-admin.icon name="comment" size="16" /> @endif
                        </span>
                        <span>{{ __($label) }}</span>
                        <span aria-hidden="true" class="muted" style="margin-left: auto">+</span>
                    </button>
                @endforeach
            </div>
        </x-admin.card>

        <x-admin.folio side="left" style="margin-top: auto" />
    </section>

    {{-- Right page: one webhook --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sky)">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            @if ($chosen)
                <form wire:submit="save" class="forms-edit ribbon-clear">
                    @if ($chosen === 'new')
                        @php $startHint = collect($starts)->first(fn ($s) => $s[2] === $format && $s[1] === $events)[3] ?? null; @endphp
                        <p class="eyebrow">{{ __('A new webhook') }}</p>
                        @if ($startHint) <p class="field__hint" style="margin: -6px 0 0">{{ __($startHint) }}</p> @endif
                    @endif

                    <div class="field">
                        <label for="hook-name">{{ __('Its name') }}</label>
                        <input id="hook-name" type="text" class="forms-edit__name" wire:model="name" maxlength="80">
                        @error('name') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="hook-url">{{ __('The address to call') }}</label>
                        <input id="hook-url" type="url" class="input" wire:model.blur="url" placeholder="https://" spellcheck="false" autocomplete="off" style="font-family: var(--mono); font-size: 13px">
                        @error('url') <p class="field__error">{{ $message }}</p> @enderror
                        @if ($hook) <p class="field__hint">{{ __('Booklet sends a POST to it. Only public addresses: nothing inside the server’s own network.') }}</p> @endif
                    </div>

                    <div class="field">
                        <label for="hook-format">{{ __('What it sends') }}</label>
                        <select id="hook-format" class="input" wire:model.live="format" style="max-width: 420px">
                            @foreach ($formats as $k => $words) <option value="{{ $k }}">{{ __($words) }}</option> @endforeach
                        </select>
                    </div>

                    <fieldset class="hook-events">
                        <legend class="field__label">{{ __('When to call it') }}</legend>
                        @foreach ($eventList as $k => [$label, $hint])
                            <label class="check hook-events__item">
                                <input type="checkbox" value="{{ $k }}" wire:model="events">
                                <span><span>{{ __($label) }}</span> <span class="hook-events__hint">{{ __($hint) }}</span></span>
                            </label>
                        @endforeach
                        @error('events') <p class="field__error">{{ $message }}</p> @enderror
                    </fieldset>

                    <fieldset class="hook-events">
                        <legend class="field__label">{{ __('For posts and pages in') }}</legend>
                        <label class="check"><input type="checkbox" wire:model.live="allCollections"> {{ __('Every chapter') }}</label>
                        @unless ($allCollections)
                            <div class="hook-chapters">
                                @foreach ($chapters as $c)
                                    <label class="check"><input type="checkbox" value="{{ $c['handle'] }}" wire:model="collections"> {{ __($c['title']) }}</label>
                                @endforeach
                            </div>
                        @endunless
                        @error('collections') <p class="field__error">{{ $message }}</p> @enderror
                    </fieldset>

                    @if ($hook)
                        <div class="setting-rows">
                            <div class="setting-row">
                                <span>
                                    <span id="hook-active" style="display: block; font-size: 15px; font-weight: 500">{{ __('Switched on') }}</span>
                                    <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Off: nothing is sent, and nothing waits to be.') }}</span>
                                </span>
                                <button type="button" class="switch" role="switch" aria-labelledby="hook-active" aria-checked="{{ $active ? 'true' : 'false' }}" wire:click="$toggle('active')"></button>
                            </div>
                        </div>
                    @endif

                    <div class="forms-edit__foot">
                        <button type="submit" class="btn btn--ink" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ $hook ? __('Save') : __('Add it') }}</span><span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                        </button>
                        @if ($hook)
                            <button type="button" class="btn" wire:click="test" wire:loading.attr="disabled" wire:target="test">
                                <span wire:loading.remove wire:target="test">{{ __('Send a test') }}</span><span wire:loading wire:target="test">{{ __('Sending…') }}</span>
                            </button>
                            <button type="button" class="btn btn--danger btn--sm" wire:click="delete" wire:confirm="{{ __('Delete “:name”? Nothing more is sent to it.', ['name' => $hook->name]) }}">{{ __('Delete') }}</button>
                        @else
                            <button type="button" class="btn btn--quiet" wire:click="$set('chosen', null)">{{ __('Cancel') }}</button>
                        @endif
                    </div>

                    @if ($tested)
                        <p @class(['hook-result', 'is-ok' => $tested['ok']]) role="status">
                            <strong>{{ $tested['ok'] ? __('It answered :code. The webhook works.', ['code' => $tested['code']]) : ($tested['code'] ? __('It answered :code.', ['code' => $tested['code']]) : __('It didn’t go through.')) }}</strong>
                            @if (! $tested['ok'] && $tested['note']) <span>{{ __($tested['note']) }}</span> @endif
                        </p>
                    @endif
                </form>

                @if ($hook)
                    <div class="ribbon-clear hook-section">
                        <h2 class="eyebrow section-label" style="margin: 0">{{ __('Recently sent') }}</h2>
                        @if ($deliveries->isEmpty())
                            <p class="empty-note" style="margin: 0">{{ __('Nothing yet. It’s called the next time one of those things happens.') }}</p>
                        @else
                            <ul class="hook-log">
                                @foreach ($deliveries as $d)
                                    <li wire:key="d-{{ $d->id }}">
                                        <span @class(['hook-log__state', 'is-'.$d->status])>{{ ['sent' => __('Sent'), 'pending' => $d->attempts ? __('Trying again') : __('Waiting'), 'failed' => __('Didn’t go')][$d->status] ?? $d->status }}</span>
                                        <details class="hook-log__what">
                                            <summary>
                                                <span class="hook-log__event">{{ $d->event === 'ping' ? __('A test') : __(\App\Support\Webhooks::EVENTS[$d->event][0] ?? $d->event) }}</span>
                                                @if (! empty($d->payload['entry']['title'])) <span class="hook-log__subject">“{{ \Illuminate\Support\Str::limit($d->payload['entry']['title'], 60) }}”</span> @endif
                                                <span class="hook-log__when">
                                                    {{ ($d->sent_at ?? $d->updated_at)?->diffForHumans() }}@if ($d->response_code) · {{ $d->response_code }}@endif
                                                    @if ($d->status === 'pending' && $d->due_at->isFuture()) · {{ __('next try :when', ['when' => $d->due_at->diffForHumans()]) }}@endif
                                                </span>
                                            </summary>
                                            @if ($d->response_note && $d->status !== 'sent') <p class="field__hint" style="margin: 6px 0 0">{{ __($d->response_note) }}</p> @endif
                                            <pre class="hook-log__body"><code>{{ json_encode(\App\Support\Webhooks::body($hook->format, $d->payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                                        </details>
                                        @if ($d->status !== 'sent')
                                            <button type="button" class="link-button" wire:click="resend({{ $d->id }})">{{ __('Send again') }}</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <p class="field__hint" style="margin: 0">{{ __('An address that doesn’t answer is tried again after 1, 5, 30 and 120 minutes. Changes to a live page wait a minute, so a few quick saves send once.') }}</p>
                    </div>

                    <div class="ribbon-clear hook-section">
                        <h2 class="eyebrow section-label" style="margin: 0">{{ __('Checking it came from Booklet') }}</h2>
                        <p class="field__hint" style="margin: 0">{!! __('Each call carries :sig: “sha256=” and an HMAC-SHA256 of :what made with this secret. Zapier, Make and static hosts don’t need it; your own code can check it.', ['sig' => '<code>X-Booklet-Signature</code>', 'what' => '<code>{X-Booklet-Timestamp}.{body}</code>']) !!}</p>
                        <div class="api-fresh__key">
                            <code>{{ $secret ?? 'whsec_••••••••••••••••' }}</code>
                            @if ($secret)
                                <button type="button" class="btn btn--sm" x-on:click="copy(@js($secret), 'secret')"><span x-text="copied === 'secret' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button>
                            @else
                                <button type="button" class="btn btn--sm" wire:click="revealSecret">{{ __('Show') }}</button>
                            @endif
                            <button type="button" class="btn btn--quiet btn--sm" wire:click="newSecret" wire:confirm="{{ __('Make a new secret? Code checking the old one will stop trusting the calls.') }}">{{ __('New secret') }}</button>
                        </div>
                        @php $check = "// PHP\n\$expected = 'sha256=' . hash_hmac('sha256', \$_SERVER['HTTP_X_BOOKLET_TIMESTAMP'] . '.' . file_get_contents('php://input'), \$secret);\n\$trusted = hash_equals(\$expected, \$_SERVER['HTTP_X_BOOKLET_SIGNATURE'] ?? '');"; @endphp
                        <div class="api-example">
                            <div class="api-example__head">
                                <span class="field__label" style="margin: 0">{{ __('Checking it in PHP') }}</span>
                                <button type="button" class="btn btn--sm" x-on:click="copy(@js($check), 'check')"><span x-text="copied === 'check' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button>
                            </div>
                            <pre><code>{{ $check }}</code></pre>
                        </div>
                    </div>
                @else
                    <div class="ribbon-clear hook-section">
                        <h2 class="eyebrow section-label" style="margin: 0">{{ __('What it sends') }}</h2>
                        <pre class="hook-log__body"><code>{{ $sample }}</code></pre>
                    </div>
                @endif
            @else
                <div class="ribbon-clear" style="max-width: 520px; display: flex; flex-direction: column; gap: 14px">
                    <p class="empty-note" style="margin: 0">{{ __('Choose what a new webhook is for, on the left. It starts with the right things switched on.') }}</p>
                    <div class="backup-notes">
                        <p><strong>{{ __('A static site:') }}</strong> {{ __('Netlify, Vercel and Cloudflare Pages give you a “build hook” address. Booklet calls it whenever a post or page goes live, changes or comes down, and the site rebuilds.') }}</p>
                        <p><strong>{{ __('Slack or Discord:') }}</strong> {{ __('a short message in a channel: a new post, a comment, a form answer.') }}</p>
                        <p><strong>{{ __('Zapier or Make:') }}</strong> {{ __('everything about what happened, as JSON, for the next step: a tweet, a row in a spreadsheet, an email.') }}</p>
                    </div>
                </div>
            @endif
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
