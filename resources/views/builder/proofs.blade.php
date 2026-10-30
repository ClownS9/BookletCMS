{{--
    Sharing the draft with someone without an account: a private link (a
    proof copy) where they can read it, and leave notes. Their notes show
    here, to see to.
--}}
@if ($proofing)
    <details class="d-proofs" @if ($proofing['notes'] !== [] || in_array(request()->input('action'), ['proof', 'proof-stop', 'proof-note'], true) || ! empty($errors['proof'])) open @endif>
        <summary>{{ __('Share the draft') }}{{ $proofing['notes'] !== [] ? ' · '.trans_choice(':count note to see to|:count notes to see to', count($proofing['notes'])) : '' }}</summary>
        <p class="d-note">{{ __('A private link for someone without an account: they read the page as it is now, and can leave notes. Nothing goes on the site.') }}</p>

        @foreach ($proofing['links'] as $link)
            <div class="d-proof">
                <span class="d-proof__who">{{ $link['label'] ?: 'A link' }}</span>
                <span class="d-proof__meta">{{ __('until :when', ['when' => $link['closes']]) }} · {{ trans_choice('opened :count time|opened :count times', $link['opens']) }}@if ($link['notes']) · {{ trans_choice(':count note|:count notes', $link['notes']) }}@endif</span>
                <span class="d-proof__do">
                    @if ($link['url']) <button type="button" class="d-link" data-copy="{{ $link['url'] }}">{{ __('Copy the link') }}</button> @endif
                    <button type="button" class="d-link d-danger" data-do="proof-stop" data-payload="{{ $link['id'] }}" data-confirm="Stop this link? Whoever has it can’t open the page any more.">{{ __('Stop it') }}</button>
                </span>
            </div>
        @endforeach

        <form class="d-form d-proof-new" data-form="proof">
            <label class="d-field">
                <span>{{ __('Who it’s for') }} <i>{{ __('(optional)') }}</i></span>
                <input type="text" name="label" maxlength="80" placeholder="{{ __('Aisha, the shop owner…') }}">
            </label>
            <label class="d-field">
                <span>{{ __('Works for') }}</span>
                <select name="days">
                    @foreach (\App\Models\Proof::LASTS as $days => $words)
                        <option value="{{ $days }}" @selected($days === 7)>{{ __($words) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="d-check"><input type="checkbox" name="notes" value="1" checked> {{ __('They can leave notes') }}</label>
            @if (! empty($errors['proof'])) <em class="d-error">{{ $errors['proof'] }}</em> @endif
            <button type="submit" class="d-btn">{{ __('Make a link') }}</button>
        </form>

        @if ($proofing['notes'] !== [])
            <h3 class="d-group">{{ __('Notes to see to') }}</h3>
            <ul class="d-proof-notes">
                @foreach ($proofing['notes'] as $note)
                    <li>
                        <p class="d-proof-notes__who"><b>{{ $note['name'] }}</b> · {{ $note['when'] }}</p>
                        @if ($note['quote']) <blockquote>“{{ $note['quote'] }}”</blockquote> @endif
                        <p class="d-proof-notes__body">{{ $note['body'] }}</p>
                        <button type="button" class="d-link" data-do="proof-note" data-payload="{{ $note['id'] }}">{{ __('Seen to') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($proofing['done'] > 0)
            <p class="d-note">{{ __(':done seen to · all of them are in the book, under this page’s proof copies.', ['done' => $proofing['done']]) }}</p>
        @endif
    </details>
@endif
