<section class="team-notes" aria-labelledby="team-notes-title">
    <h2 id="team-notes-title" class="field__label" style="display: flex; align-items: baseline; gap: 8px">
        Sticky notes for the team
        @if ($open->isNotEmpty()) <span class="team-notes__count">{{ $open->count() }}</span> @endif
    </h2>

    @if ($open->isNotEmpty())
        <ul class="team-notes__list">
            @foreach ($open as $i => $note)
                <li class="sticky-note" wire:key="note-{{ $note->id }}" style="--tone: var(--{{ $note->tone }}); --tilt: {{ ['-1.4deg', '1.1deg', '-0.6deg', '1.6deg'][$i % 4] }}">
                    <p class="sticky-note__body">{{ $note->body }}</p>
                    <p class="sticky-note__who">{{ $note->user?->name ?? 'Someone' }} · {{ $note->created_at->diffForHumans(short: true) }}</p>
                    <div class="sticky-note__tools">
                        <button type="button" class="sticky-note__done" wire:click="toggleDone({{ $note->id }})" title="Done">✓ Done</button>
                        @if ($note->user_id === request()->user()?->id || in_array(request()->user()->level(), ['admin', 'editor'], true))
                            <button type="button" class="sticky-note__remove" wire:click="remove({{ $note->id }})" wire:confirm="Throw this note away?" aria-label="Throw away">×</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="team-notes__new">
        <label for="new-team-note" class="sr-only">New note</label>
        <textarea id="new-team-note" class="input" rows="2" wire:model="body" maxlength="1000"
                  placeholder="“Check this quote”, “needs a photo”…"
                  x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.add() }"></textarea>
        <div class="team-notes__row">
            <div class="team-notes__tones" role="radiogroup" aria-label="Note colour">
                @foreach ($tones as $t)
                    <button type="button" role="radio" aria-checked="{{ $tone === $t ? 'true' : 'false' }}" aria-label="{{ ucfirst($t) }}"
                            wire:click="$set('tone', '{{ $t }}')" style="--tone: var(--{{ $t }})"></button>
                @endforeach
            </div>
            <button type="button" class="btn btn--sm" wire:click="add" wire:loading.attr="disabled" wire:target="add">Stick it on</button>
        </div>
        @error('body') <p class="field__error">{{ $message }}</p> @enderror
    </div>

    @if ($done->isNotEmpty())
        <button type="button" class="link-button team-notes__toggle" wire:click="$toggle('showDone')">
            {{ $showDone ? 'Hide' : 'Show' }} {{ $done->count() }} done
        </button>
        @if ($showDone)
            <ul class="team-notes__done">
                @foreach ($done as $note)
                    <li wire:key="done-{{ $note->id }}">
                        <button type="button" class="link-button" wire:click="toggleDone({{ $note->id }})" title="Not done after all">↺</button>
                        <s>{{ $note->body }}</s>
                        <span class="muted">· {{ $note->doneBy?->name ?? 'Someone' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</section>
