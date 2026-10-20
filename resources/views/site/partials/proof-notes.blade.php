{{-- Proof copies: notes in the margin from someone reading before it's published. Works without scripts (a plain form); with them, picking out words fills in the quote. --}}
@php $left = $proof['left']; $noted = session('proof_noted'); @endphp
<details class="proof-notes" id="proof-notes" @if ($noted || $errors->any()) open @endif data-proof-notes>
    <summary class="proof-notes__tab">
        <span>Notes</span>
        @if ($left->isNotEmpty()) <span class="proof-notes__count">{{ $left->count() }}</span> @endif
    </summary>

    <div class="proof-notes__panel" role="region" aria-label="Notes on this proof copy">
        <p class="proof-notes__intro">Spotted something? Pick out the words on the page, then say what you think. Only the people working on this see your notes.</p>

        @if ($noted)
            <p class="proof-notes__thanks" role="status">Thank you. Your note is in the margin for them now.</p>
        @endif

        <form method="POST" action="{{ route('proof.note', $proof['secret']) }}" class="proof-notes__form">
            @csrf
            <div class="proof-notes__quote" data-proof-quote @if (! old('quote')) hidden @endif>
                <span class="proof-notes__quote-label">About</span>
                <q data-proof-quote-text>{{ old('quote') }}</q>
                <button type="button" class="proof-notes__clear" data-proof-clear aria-label="Not about these words">×</button>
            </div>
            <input type="hidden" name="quote" value="{{ old('quote') }}" data-proof-quote-input>

            <label for="proof-name">Your name</label>
            <input id="proof-name" name="name" type="text" maxlength="60" required value="{{ old('name', session('proof_name')) }}" autocomplete="name">
            @error('name') <p class="proof-notes__error">{{ $message }}</p> @enderror

            <label for="proof-body">Your note</label>
            <textarea id="proof-body" name="body" rows="4" maxlength="1000" required placeholder="“This sentence is a bit long”, “love this photo”…">{{ old('body') }}</textarea>
            @error('body') <p class="proof-notes__error">{{ $message }}</p> @enderror

            {{-- For robots only: people never see it. --}}
            <div class="proof-notes__trap" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <button type="submit" class="button button--ink">Leave the note</button>
        </form>

        @if ($left->isNotEmpty())
            <h2 class="proof-notes__heading">Left so far</h2>
            <ol class="proof-notes__list">
                @foreach ($left as $n)
                    <li @class(['is-done' => $n->resolved_at])>
                        @if ($n->quote) <q>{{ $n->quote }}</q> @endif
                        <p>{{ $n->body }}</p>
                        <span>{{ $n->name }} · {{ $n->created_at->diffForHumans() }}@if ($n->resolved_at) · seen to ✓ @endif</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</details>

<button type="button" class="proof-pick" data-proof-pick hidden>Note on this</button>
