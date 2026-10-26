{{--
    Sharing the draft with someone without an account: a private link (a
    proof copy) where they can read it, and leave notes. Their notes show
    here, to see to.
--}}
@if ($proofing)
    <details class="d-proofs" @if ($proofing['notes'] !== [] || in_array(request()->input('action'), ['proof', 'proof-stop', 'proof-note'], true) || ! empty($errors['proof'])) open @endif>
        <summary>Share the draft{{ $proofing['notes'] !== [] ? ' · '.count($proofing['notes']).' '.str('note')->plural(count($proofing['notes'])).' to see to' : '' }}</summary>
        <p class="d-note">A private link for someone without an account: they read the page as it is now, and can leave notes. Nothing goes on the site.</p>

        @foreach ($proofing['links'] as $link)
            <div class="d-proof">
                <span class="d-proof__who">{{ $link['label'] ?: 'A link' }}</span>
                <span class="d-proof__meta">until {{ $link['closes'] }} · opened {{ $link['opens'] }} {{ str('time')->plural($link['opens']) }}@if ($link['notes']) · {{ $link['notes'] }} {{ str('note')->plural($link['notes']) }}@endif</span>
                <span class="d-proof__do">
                    @if ($link['url']) <button type="button" class="d-link" data-copy="{{ $link['url'] }}">Copy the link</button> @endif
                    <button type="button" class="d-link d-danger" data-do="proof-stop" data-payload="{{ $link['id'] }}" data-confirm="Stop this link? Whoever has it can’t open the page any more.">Stop it</button>
                </span>
            </div>
        @endforeach

        <form class="d-form d-proof-new" data-form="proof">
            <label class="d-field">
                <span>Who it’s for <i>(optional)</i></span>
                <input type="text" name="label" maxlength="80" placeholder="Aisha, the shop owner…">
            </label>
            <label class="d-field">
                <span>Works for</span>
                <select name="days">
                    @foreach (\App\Models\Proof::LASTS as $days => $words)
                        <option value="{{ $days }}" @selected($days === 7)>{{ $words }}</option>
                    @endforeach
                </select>
            </label>
            <label class="d-check"><input type="checkbox" name="notes" value="1" checked> They can leave notes</label>
            @if (! empty($errors['proof'])) <em class="d-error">{{ $errors['proof'] }}</em> @endif
            <button type="submit" class="d-btn">Make a link</button>
        </form>

        @if ($proofing['notes'] !== [])
            <h3 class="d-group">Notes to see to</h3>
            <ul class="d-proof-notes">
                @foreach ($proofing['notes'] as $note)
                    <li>
                        <p class="d-proof-notes__who"><b>{{ $note['name'] }}</b> · {{ $note['when'] }}</p>
                        @if ($note['quote']) <blockquote>“{{ $note['quote'] }}”</blockquote> @endif
                        <p class="d-proof-notes__body">{{ $note['body'] }}</p>
                        <button type="button" class="d-link" data-do="proof-note" data-payload="{{ $note['id'] }}">Seen to</button>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($proofing['done'] > 0)
            <p class="d-note">{{ $proofing['done'] }} seen to · all of them are in the book, under this page’s proof copies.</p>
        @endif
    </details>
@endif
