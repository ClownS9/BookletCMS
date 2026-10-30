<div class="orb" x-data="stacyVoice(@js($this->voice + ['greets' => true]))" :data-state="state" :data-awake="handsFree ? 'yes' : null" :class="{ 'orb--open': $wire.open, 'orb--hands-free': handsFree }"
     x-on:stacy-toggle.window="$wire.open = ! $wire.open; $wire.open && $nextTick(() => document.getElementById('orb-ask')?.focus())"
     x-on:click.outside="if ($wire.open && ! $event.target.closest('[data-stacy-dock], .rail-tip')) { $wire.open = false }"
     x-effect="window.bookletStacyDock?.state($wire.open, state)">
    @if ($this->enabled && $this->brain)
        {{-- The little card --}}
        <section class="orb__card" id="orb-card" x-show="$wire.open" x-cloak
                 x-transition:enter="orb-in" x-transition:enter-start="orb-in--from" x-transition:leave="orb-out" x-transition:leave-end="orb-in--from"
                 aria-label="{{ __('Stacy') }}" @keydown.escape.stop="$wire.open = false; stopAll()">
            <header class="orb__head">
                <span class="orb__avatar" aria-hidden="true"><x-admin.icon name="stacy" size="16" /></span>
                <span style="flex: 1 1 auto; min-width: 0">
                    <span class="serif" style="display: block; font-size: 17px; line-height: 1.1">{{ __('Stacy') }}</span>
                    <span class="orb__status" x-text="label" aria-live="polite"></span>
                </span>
                <button type="button" class="orb__tool" @click="toggleSpeak()" :aria-pressed="speakReplies.toString()" :title="speakReplies ? 'Spoken answers on' : 'Spoken answers off'" x-show="canSpeak">
                    <span x-show="speakReplies"><x-admin.icon name="speaker" size="16" /></span>
                    <span x-show="!speakReplies" x-cloak><x-admin.icon name="speaker-off" size="16" /></span>
                    <span class="sr-only">{{ __('Spoken answers') }}</span>
                </button>
                @if ($this->voice['wake'])
                    <button type="button" class="orb__tool" @click="toggleHandsFree()" :aria-pressed="handsFree.toString()" :class="{ 'is-on': handsFree }" title="{{ __('Hands-free: say “Stacy…”') }}">
                        <x-admin.icon name="ear" size="16" /><span class="sr-only">{{ __('Hands-free') }}</span>
                    </button>
                @endif
                <a href="{{ route('admin.stacy') }}" class="orb__tool" title="{{ __('Open the full page') }}" wire:navigate><x-admin.icon name="expand" size="16" /><span class="sr-only">{{ __('Open Ask Stacy') }}</span></a>
                <button type="button" class="orb__tool" @click="$wire.open = false; stopAll()" title="{{ __('Close') }}"><x-admin.icon name="close" size="16" /><span class="sr-only">{{ __('Close') }}</span></button>
            </header>

            <ol class="orb__log" x-ref="log" x-init="stickToBottom($el)" x-effect="$wire.open && $nextTick(() => $refs.log.scrollTop = $refs.log.scrollHeight)">
                @forelse ($recent as $message)
                    @php $meta = (array) $message->meta; @endphp
                    <li wire:key="orb-{{ $message->id }}" @class(['orb__line', 'orb__line--mine' => $message->role === 'user', 'orb__line--error' => $meta['error'] ?? false])>
                        @if ($message->role === 'assistant' && ! ($meta['error'] ?? false))
                            <div class="orb__words">{{ \App\Stacy\Chat::html((string) $message->content) }}</div>
                        @else
                            <p>{{ $message->content }}</p>
                        @endif

                        @if ($meta['error'] ?? false)
                            <button type="button" class="btn btn--sm" style="margin-top: 8px" wire:click="retry({{ $message->id }})">{{ __('Try again') }}</button>
                        @elseif ($message->role === 'assistant')
                            @include('livewire.admin.partials.stacy-message-tools', ['message' => $message])
                        @endif

                        @if (! empty($meta['links']))
                            <div class="orb__links">
                                @foreach (array_slice($meta['links'], 0, 4) as $link)
                                    <a href="{{ $link['href'] }}" wire:navigate><span class="dot" style="--dot: {{ $link['tone'] ?? 'var(--sand)' }}; --tilt: 0deg; margin: 0"></span>{{ __($link['label']) }}</a>
                                @endforeach
                            </div>
                        @endif

                        @if (! empty($meta['pending']) && ! isset($meta['resolved']))
                            <div class="orb__confirm">
                                <button type="button" class="btn btn--yes btn--sm" wire:click="confirm({{ $message->id }})">{{ $meta['pending']['button'] ?? __('Yes') }}</button>
                                <button type="button" class="btn btn--sm" wire:click="cancel({{ $message->id }})">{{ __('Not now') }}</button>
                            </div>
                        @elseif (! empty($meta['pending']))
                            <p class="orb__resolved">{{ ['done' => '✓ Done', 'cancelled' => 'Left as it was', 'expired' => 'No longer waiting'][$meta['resolved']] ?? '' }}</p>
                        @endif
                    </li>
                @empty
                    <li class="orb__line">
                        <p>Hi {{ str(request()->user()->name)->before(' ') }}. Ask me anything about the book, out loud or typed.</p>
                    </li>
                @endforelse

                <li class="orb__line orb__line--mine orb__line--live" x-show="heard" x-cloak><p x-text="heard"></p></li>
                <li class="orb__line orb__thinking" wire:loading.block wire:target="ask, hearVoiceNote, confirm, cancel, answerLatest">
                    <p>{{ __('Thinking') }}<span>.</span><span>.</span><span>.</span></p>
                </li>
            </ol>

            @if ($chips)
                <div class="orb__chips" wire:loading.remove wire:target="ask, hearVoiceNote">
                    @foreach ($chips as [$label, $send])
                        <button type="button" class="orb__chip" wire:click="ask(@js($send))" x-on:click="typed(@js($label))">{{ __($label) }}</button>
                    @endforeach
                </div>
            @endif

            <p class="orb__note" x-show="note" x-text="note" x-cloak role="alert"></p>

            <form class="orb__box" wire:submit="ask()" x-on:submit="typed($wire.draft)">
                @include('livewire.admin.partials.stacy-slash')
                <label for="orb-ask" class="sr-only">{{ __('Ask Stacy') }}</label>
                <input id="orb-ask" type="text" wire:model="draft" placeholder="{{ __('Type, talk, or / for shortcuts…') }}" x-on:keydown="slashKey($event)" autocomplete="off" maxlength="4000" wire:loading.attr="disabled" wire:target="ask, hearVoiceNote">
                <button type="button" class="orb__mic" x-show="supported" @click="toggle()" :aria-pressed="(state === 'listening').toString()" :title="label">
                    <x-admin.icon name="mic" size="18" /><span class="sr-only">{{ __('Talk') }}</span>
                </button>
                <button type="submit" class="orb__send" aria-label="{{ __('Send') }}" wire:loading.attr="disabled" wire:target="ask, hearVoiceNote">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>
            </form>
        </section>

        {{-- Her hello, when someone arrives --}}
        @if ($greeting)
            <aside class="orb__greet" wire:key="greet-{{ md5(json_encode($greeting)) }}" x-data="stacyGreeting(@js($greeting))"
                   x-show="shown && !$wire.open" x-cloak role="status" aria-label="{{ __('Stacy says hello') }}"
                   x-on:mouseenter="hold()" x-on:mouseleave="release()" x-on:focusin="hold()"
                   x-transition:enter="greet-in" x-transition:enter-start="greet-in--from" x-transition:leave="greet-out" x-transition:leave-end="greet-in--from">
                {{-- The whole greeting for screen readers; the typed version is just for show --}}
                <p class="sr-only">{{ $greeting['title'] }} {{ implode(' ', $greeting['lines']) }}</p>

                <button type="button" class="orb__greet-close" x-on:click="close()" title="{{ __('Close') }}"><x-admin.icon name="close" size="14" /><span class="sr-only">{{ __('Close') }}</span></button>

                <p class="orb__greet-title serif" aria-hidden="true"><span x-text="title"></span><span class="orb__greet-caret" x-show="!done"></span></p>
                <template x-for="(line, i) in lines" :key="i">
                    <p class="orb__greet-line" aria-hidden="true" x-text="line"></p>
                </template>

                <div class="orb__greet-actions" x-show="done" x-transition.opacity.duration.300ms>
                    @foreach ($greeting['actions'] as $action)
                        @if (isset($action['href']))
                            <a href="{{ $action['href'] }}" class="orb__chip" wire:navigate x-on:click="close(true)">{{ __($action['label']) }}</a>
                        @else
                            <button type="button" class="orb__chip" x-on:click="close(true); $wire.open = true; typed(@js($action['label']))" wire:click="ask(@js($action['ask']))">{{ __($action['label']) }}</button>
                        @endif
                    @endforeach
                </div>

                <button type="button" class="orb__greet-off link-button" x-show="done" wire:click="stopGreetings">{{ __('Don’t greet me') }}</button>
            </aside>
        @endif

        {{-- The orb itself --}}
        <button type="button" class="orb__button" @click="$wire.open = true; supported && state !== 'thinking' && toggle()" :title="label + ' (Alt+S)'" :aria-expanded="$wire.open.toString()">
            <span class="orb__ring" aria-hidden="true"></span>
            <span class="orb__ring orb__ring--2" aria-hidden="true"></span>
            <span class="orb__core" aria-hidden="true">
                <stacy-holo class="orb__holo" wire:ignore></stacy-holo>
            </span>
            <span class="sr-only" x-text="label"></span>
        </button>
    @endif
</div>
