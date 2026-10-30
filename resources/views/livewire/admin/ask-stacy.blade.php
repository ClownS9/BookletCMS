<div style="display: contents" @if ($q !== '') wire:init="askFromSearch" @endif
     x-data="stacyVoice(@js($this->voice))" :data-voice="state">
    {{-- Left page: who Stacy is, which brain she's using, ideas --}}
    <section class="page page--left" style="flex-basis: 340px">
        <div class="stacy-badge stacy-badge--holo" aria-hidden="true" :data-state="state" :data-awake="handsFree ? 'yes' : null"
             x-on:click="supported && state !== 'thinking' && toggle()" :title="label">
            <stacy-holo wire:ignore></stacy-holo>
            <span class="stacy-badge__status" x-text="label"></span>
        </div>
        <h1 class="display display--page" style="margin-top: 22px">{{ __('Ask Stacy') }}</h1>
        <p class="lede" style="margin-top: 12px; font-size: 15px">{{ __('Your helper inside Booklet. Stacy finds things, takes you to the right page, and does small jobs like scheduling a post. She always asks before changing anything.') }}</p>

        <div class="brain-card">
            @if ($this->brain)
                <span class="eyebrow" style="font-size: 11px">{{ __('Thinking with') }}</span>
                <span class="serif" style="display: block; margin-top: 4px; font-size: 18px">{{ $this->brain[0]->name() }}</span>
                <span style="display: block; font-family: var(--mono); font-size: 12px; color: var(--muted); overflow-wrap: anywhere">{{ $this->brain[1] }}</span>
                <a href="{{ route('admin.account') }}#stacy" style="display: inline-block; margin-top: 8px; font-size: 13px" wire:navigate>{{ __('Choose another model →') }}</a>
            @else
                <span class="serif" style="display: block; font-size: 18px">{{ __('No brain yet') }}</span>
                <span style="display: block; margin-top: 4px; font-size: 13px; color: var(--muted)">
                    @if (request()->user()->isAdmin())
                        Turn on an AI service and add its key.
                    @else
                        An admin needs to turn on an AI service first.
                    @endif
                </span>
                @if (request()->user()->isAdmin())
                    <a href="{{ route('admin.settings.stacy') }}" class="btn btn--ink btn--sm" style="margin-top: 12px" wire:navigate>{{ __('Give Stacy a brain') }}</a>
                @endif
            @endif
        </div>

        @if ($this->brain && $this->enabled)
            <h2 class="eyebrow section-label section-label--gap">{{ __('Try asking') }}</h2>
            <div style="display: flex; flex-direction: column; gap: 8px">
                @foreach ($suggestions as [$text, $tone])
                    <button type="button" class="suggestion" wire:click="ask(@js($text))" x-on:click="typed(@js($text))" wire:loading.attr="disabled" wire:target="ask">
                        <span class="dot" style="--dot: {{ $tone }}; --tilt: 0deg; margin: 0"></span>{{ __($text) }}
                    </button>
                @endforeach
            </div>
        @endif

        @if ($earlier->isNotEmpty())
            <h2 class="eyebrow section-label section-label--gap">{{ __('Earlier pages') }}</h2>
            <ul class="earlier">
                @foreach ($earlier as $page)
                    <li wire:key="earlier-{{ $page->id }}">
                        <button type="button" class="earlier__open" wire:click="openConversation({{ $page->id }})" aria-pressed="{{ $conversationId === $page->id ? 'true' : 'false' }}">
                            <span class="earlier__title">{{ $page->title ?: 'A conversation' }}</span>
                            <span class="earlier__when">{{ $page->updated_at?->diffForHumans(short: true) }}</span>
                        </button>
                        <button type="button" class="icon-btn earlier__tear" wire:click="deleteConversation({{ $page->id }})"
                                wire:confirm="{{ __('Tear this page out? The conversation will be gone.') }}" aria-label="{{ __('Delete “:name”', ['name' => $page->title]) }}">
                            <x-admin.icon name="close" size="14" />
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the conversation --}}
    <section class="page page--right" style="--ribbon: #1E1C18" aria-label="{{ __('Conversation with Stacy') }}">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear" style="display: flex; justify-content: space-between; align-items: center; gap: 12px; padding-bottom: 14px; border-bottom: 1px solid var(--rule)">
            <span class="eyebrow">{{ $this->messages->isEmpty() ? __('A fresh page') : $this->messages->first()->created_at->format('l j F') }}</span>
            @if ($this->messages->isNotEmpty())
                <button type="button" class="btn btn--quiet btn--sm" wire:click="newPage">{{ __('New page') }}</button>
            @endif
        </div>

        @unless ($this->enabled)
            <p class="empty-note">{{ __('Stacy is switched off for this site. An admin can turn her back on in Settings.') }}</p>
        @endunless

        <ol class="chat" aria-live="polite" aria-relevant="additions" x-init="stickToBottom($el)">
            @if ($this->messages->isEmpty() && $this->enabled)
                <li class="chat__theirs">
                    <p class="eyebrow chat__who">{{ __('Stacy') }}</p>
                    <p class="serif chat__text">Hello {{ str(request()->user()->name)->before(' ') }}. {{ $this->brain ? __('What can I find or do for you?') : (request()->user()->isAdmin() ? __('I can’t think yet: give me a brain (an AI service) and I’m ready.') : __('I can’t think yet: an admin needs to give me a brain (an AI service) first.')) }}</p>
                </li>
            @endif

            @foreach ($this->messages as $message)
                @if ($message->role === 'user')
                    <li class="chat__mine" wire:key="m-{{ $message->id }}">
                        <p>@if (($message->meta['via'] ?? null) === 'voice')<span class="chat__via" title="{{ __('Said out loud') }}"><x-admin.icon name="mic" size="13" /></span>@endif{{ $message->content }}</p>
                    </li>
                @else
                    @php $meta = (array) $message->meta; @endphp
                    <li class="chat__theirs" wire:key="m-{{ $message->id }}">
                        <p class="eyebrow chat__who">{{ __('Stacy') }}</p>
                        @if ($message->role === 'assistant' && ! ($meta['error'] ?? false))
                            <div class="serif chat__text chat__text--rich">{{ \App\Stacy\Chat::html((string) $message->content) }}</div>
                        @else
                            <p @class(['serif', 'chat__text', 'chat__text--error' => $meta['error'] ?? false])>{{ $message->content }}</p>
                        @endif
                        @if ($meta['error'] ?? false)
                            <button type="button" class="btn btn--sm" style="margin-top: 10px" wire:click="retry({{ $message->id }})">{{ __('Try again') }}</button>
                        @endif

                        @if (! empty($meta['links']))
                            <div class="chat__links">
                                @foreach ($meta['links'] as $link)
                                    <a href="{{ $link['href'] }}" wire:navigate>
                                        <span class="dot" style="--dot: {{ $link['tone'] ?? 'var(--sand)' }}; --tilt: 0deg"></span>
                                        <span class="serif" style="flex: 1 1 auto; font-size: 17px">{{ __($link['label']) }}</span>
                                        <span style="font-size: 13px; color: var(--faint)">{{ $link['meta'] ?? '' }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        @if (! empty($meta['pending']))
                            @if (! isset($meta['resolved']))
                                <div class="chat__confirm">
                                    <p style="font-size: 14px; color: var(--muted)">{{ $meta['pending']['summary'] }}</p>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap">
                                        <button type="button" class="btn btn--yes" wire:click="confirm({{ $message->id }})">{{ $meta['pending']['button'] ?? __('Yes') }}</button>
                                        <button type="button" class="btn" wire:click="cancel({{ $message->id }})">{{ __('Not now') }}</button>
                                    </div>
                                </div>
                            @else
                                <p class="chat__resolved">{{ ['done' => '✓ Done', 'cancelled' => 'Left as it was', 'expired' => 'No longer waiting'][$meta['resolved']] ?? '' }}</p>
                            @endif
                        @endif

                        @unless ($meta['error'] ?? false)
                            @include('livewire.admin.partials.stacy-message-tools', ['message' => $message])
                        @endunless

                        @if (! empty($meta['model']))
                            <p class="chat__model">{{ $meta['model'] }}</p>
                        @endif
                    </li>
                @endif
            @endforeach

            <li class="chat__mine chat__mine--live" x-show="heard" x-cloak><p x-text="heard"></p></li>

            <li class="chat__theirs" wire:loading.block wire:target="ask, askFromSearch, hearVoiceNote, confirm, cancel, answerLatest">
                <p class="eyebrow chat__who">{{ __('Stacy') }}</p>
                <p class="serif chat__text chat__thinking">{{ __('Thinking') }}<span>.</span><span>.</span><span>.</span></p>
            </li>
        </ol>

        @if ($this->enabled)
            <div class="voice-strip" x-show="state !== 'idle' || note || handsFree" x-cloak>
                <span class="voice-strip__wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
                <span class="voice-strip__label" x-text="note || label" aria-live="polite"></span>
                <button type="button" class="link-button" x-show="state === 'speaking'" @click="hush()">{{ __('Stop') }}</button>
            </div>

            <form wire:submit="ask()" class="chat__box" x-on:submit="typed($wire.draft)">
                @include('livewire.admin.partials.stacy-slash')
                <label for="ask" class="sr-only">{{ __('Ask Stacy') }}</label>
                <input id="ask" type="text" wire:model="draft" placeholder="{{ $this->brain ? __('Ask Stacy, talk, or type / for shortcuts…') : __('Stacy needs a brain first') }}"
                       @if ($this->brain) x-bind:placeholder="matchMedia('(max-width: 600px)').matches ? 'Ask Stacy…' : 'Ask Stacy, talk, or type / for shortcuts…'" @endif
                       x-on:keydown="slashKey($event)"
                       autocomplete="off" maxlength="4000" @disabled(! $this->brain) wire:loading.attr="disabled" wire:target="ask">
                @if ($this->brain)
                    <button type="button" class="chat__tool" x-show="canSpeak" @click="toggleSpeak()" :aria-pressed="speakReplies.toString()" :title="speakReplies ? 'Stacy answers out loud' : 'Stacy answers quietly'">
                        <span x-show="speakReplies"><x-admin.icon name="speaker" size="17" /></span>
                        <span x-show="!speakReplies" x-cloak><x-admin.icon name="speaker-off" size="17" /></span>
                        <span class="sr-only">{{ __('Spoken answers') }}</span>
                    </button>
                    @if ($this->voice['wake'])
                        <button type="button" class="chat__tool" @click="toggleHandsFree()" :aria-pressed="handsFree.toString()" :class="{ 'is-on': handsFree }" title="{{ __('Hands-free: say “Stacy…”') }}">
                            <x-admin.icon name="ear" size="17" /><span class="sr-only">{{ __('Hands-free') }}</span>
                        </button>
                    @endif
                    <button type="button" class="chat__mic" x-show="supported" @click="toggle()" :aria-pressed="(state === 'listening').toString()" :title="label + ' (Alt+S)'">
                        <span class="chat__mic-ring" aria-hidden="true"></span>
                        <x-admin.icon name="mic" size="18" /><span class="sr-only">{{ __('Talk to Stacy') }}</span>
                    </button>
                @endif
                <button type="submit" aria-label="{{ __('Send') }}" @disabled(! $this->brain)>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>
            </form>
            @error('draft') <p class="field__error">{{ $message }}</p> @enderror
        @endif
        <x-admin.folio side="right" style="padding-top: 10px" />
    </section>
</div>
