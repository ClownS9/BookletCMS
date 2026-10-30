<section class="proofs" aria-labelledby="proofs-title"
         x-data="{ copied: null, copy(url, id) { navigator.clipboard?.writeText(url).then(() => { this.copied = id; setTimeout(() => this.copied = null, 1800) }) } }"
         x-on:proof-made.window="$nextTick(() => document.querySelector('.proof-link')?.querySelector('input')?.select())">
    <h2 id="proofs-title" class="field__label" style="display: flex; align-items: baseline; gap: 8px">
        Proof copies
        @if ($waiting->isNotEmpty()) <span class="team-notes__count" title="{{ __('Notes waiting') }}">{{ $waiting->count() }}</span> @endif
    </h2>
    <p class="field__hint" style="margin: 0">{{ __('A private link for someone without an account to read it as the site will show it, drafts too, and leave notes in the margin.') }}</p>

    @if ($waiting->isNotEmpty())
        <ul class="proof-margin" aria-label="{{ __('Notes from proof copies') }}">
            @foreach ($waiting as $i => $n)
                <li class="proof-margin__note" wire:key="pn-{{ $n->id }}" style="--tilt: {{ ['-1deg', '.8deg', '-.4deg'][$i % 3] }}">
                    @if ($n->quote) <q class="proof-margin__quote">{{ $n->quote }}</q> @endif
                    <p class="proof-margin__body">{{ $n->body }}</p>
                    <p class="proof-margin__who">{{ $n->name }}@if ($n->proof?->label && $n->proof->label !== $n->name) (link for {{ $n->proof->label }})@endif · {{ $n->created_at->diffForHumans(short: true) }}</p>
                    <button type="button" class="sticky-note__done" wire:click="toggleDone({{ $n->id }})">{{ __('✓ Seen to') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($open->isNotEmpty())
        <ul class="proof-links">
            @foreach ($open as $p)
                @php $url = $p->url(); @endphp
                <li class="proof-link" wire:key="proof-{{ $p->id }}">
                    <div class="proof-link__head">
                        <span class="proof-link__for">{{ $p->label ? __('For :name', ['name' => $p->label]) : __('Proof copy') }}</span>
                        <span class="proof-link__meta">
                            {{ $p->opens ? trans_choice('opened :count time, last :when|opened :count times, last :when', $p->opens, ['when' => $p->opened_at?->diffForHumans(short: true)]) : __('not opened yet') }}
                            · {{ __('closes :when', ['when' => $p->expires_at->diffForHumans(['parts' => 1])]) }}
                            @if (! $p->notes_allowed) · no notes @endif
                        </span>
                    </div>
                    @if ($url)
                        <div class="proof-link__row">
                            <label class="sr-only" for="proof-url-{{ $p->id }}">{{ __('Link') }}</label>
                            <input id="proof-url-{{ $p->id }}" type="text" class="input proof-link__url" value="{{ $url }}" readonly x-on:focus="$el.select()">
                            <button type="button" class="btn btn--sm" x-on:click="copy(@js($url), {{ $p->id }})"><span x-text="copied === {{ $p->id }} ? 'Copied' : 'Copy'">{{ __('Copy') }}</span></button>
                        </div>
                    @else
                        <p class="field__hint">{{ __('This link can’t be shown again (the app key changed). Stop it and make a new one.') }}</p>
                    @endif
                    <button type="button" class="link-button proof-link__stop" wire:click="stop({{ $p->id }})" wire:confirm="{{ __('Stop this link? Whoever has it won’t be able to open it any more.') }}">{{ __('Stop sharing') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($making)
        <form wire:submit="make" class="proof-make">
            <div class="field">
                <label for="proof-label">{{ __('Who’s it for?') }} <span class="muted">{{ __('(optional)') }}</span></label>
                <input id="proof-label" type="text" class="input" wire:model="label" maxlength="80" placeholder="{{ __('Aminah, the client…') }}">
                @error('label') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <div class="proof-make__row">
                <label for="proof-days" class="field__label" style="margin: 0">{{ __('Lasts') }}</label>
                <select id="proof-days" class="input" wire:model="days">
                    @foreach ($lasts as $d => $words) <option value="{{ $d }}">{{ __($words) }}</option> @endforeach
                </select>
                <label class="proof-make__notes"><input type="checkbox" wire:model="notes"> {{ __('They can leave notes') }}</label>
            </div>
            <div class="proof-make__row">
                <button type="submit" class="btn btn--ink btn--sm">{{ __('Make the link') }}</button>
                <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('making', false)">{{ __('Cancel') }}</button>
            </div>
        </form>
    @else
        <button type="button" class="btn btn--sm" style="align-self: flex-start" wire:click="$set('making', true)">{{ __('+ Make a proof copy') }}</button>
    @endif

    @if ($done->isNotEmpty() || $closed)
        <p class="field__hint" style="margin: 0">
            @if ($done->isNotEmpty())
                <button type="button" class="link-button" wire:click="$toggle('showDone')">{{ $showDone ? __('Hide') : __('Show') }} {{ $done->count() }} seen to</button>@if ($closed) · @endif
            @endif
            @if ($closed) {{ trans_choice(':count closed link|:count closed links', $closed) }} @endif
        </p>
        @if ($showDone)
            <ul class="team-notes__done">
                @foreach ($done as $n)
                    <li wire:key="pd-{{ $n->id }}">
                        <button type="button" class="link-button" wire:click="toggleDone({{ $n->id }})" title="{{ __('Not seen to after all') }}">↺</button>
                        <s>{{ $n->body }}</s> <span class="muted">· {{ $n->name }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</section>
