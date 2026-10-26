<section class="letter made-form" aria-label="{{ $form?->title() ?? 'A form' }}">
    @if (! $form)
        <p class="pb-nothing">This form isn’t here any more.</p>
    @elseif ($sent)
        <div class="letter__sent" role="status">
            <span class="letter__stamp" aria-hidden="true"></span>
            <p class="made-form__thanks">{{ $form->get('thanks') ?: 'Thank you. We’ve got it.' }}</p>
            <button type="button" class="button" wire:click="again">Fill it in again</button>
        </div>
    @elseif (! $open)
        <p class="made-form__closed">{{ $form->title() }} is closed for now.</p>
    @else
        <form wire:submit="send" novalidate>
            @foreach ($fields as $f)
                @php
                    $id = 'mf-'.$handle.'-'.$f['key'];
                    $err = $errors->first('values.'.$f['key']) ?: $errors->first('values.'.$f['key'].'.*');
                    $described = trim(($f['help'] !== '' ? $id.'-help ' : '').($err ? $id.'-error' : ''));
                @endphp
                @if ($f['type'] === 'choices' || ($f['type'] === 'choice' && count($f['options']) <= 6))
                    <fieldset class="field made-form__set" @if ($described) aria-describedby="{{ $described }}" @endif>
                        <legend>{{ $f['label'] }}@unless ($f['required']) <span class="muted">(optional)</span>@endunless</legend>
                        @foreach ($f['options'] as $o => $option)
                            <label class="made-form__pick">
                                @if ($f['type'] === 'choice')
                                    <input type="radio" name="{{ $id }}" value="{{ $option }}" wire:model="values.{{ $f['key'] }}" @if ($f['required']) required @endif>
                                @else
                                    <input type="checkbox" value="{{ $option }}" wire:model="values.{{ $f['key'] }}">
                                @endif
                                <span>{{ $option }}</span>
                            </label>
                        @endforeach
                        @if ($f['help'] !== '') <p class="made-form__help" id="{{ $id }}-help">{{ $f['help'] }}</p> @endif
                        @if ($err) <p class="field__error" id="{{ $id }}-error">{{ $err }}</p> @endif
                    </fieldset>
                @elseif ($f['type'] === 'tick')
                    <div class="field">
                        <label class="made-form__pick made-form__tick">
                            <input id="{{ $id }}" type="checkbox" wire:model="values.{{ $f['key'] }}" @if ($f['required']) required aria-required="true" @endif
                                   @if ($described) aria-describedby="{{ $described }}" @endif @if ($err) aria-invalid="true" @endif>
                            <span>{{ $f['label'] }}</span>
                        </label>
                        @if ($f['help'] !== '') <p class="made-form__help" id="{{ $id }}-help">{{ $f['help'] }}</p> @endif
                        @if ($err) <p class="field__error" id="{{ $id }}-error">{{ $err }}</p> @endif
                    </div>
                @else
                    <div class="field">
                        <label for="{{ $id }}">{{ $f['label'] }}@unless ($f['required']) <span class="muted">(optional)</span>@endunless</label>
                        @if ($f['type'] === 'long')
                            <textarea id="{{ $id }}" rows="5" wire:model="values.{{ $f['key'] }}" maxlength="{{ \App\Support\Forms::LONG_MAX }}"
                                      @if ($f['required']) required aria-required="true" @endif @if ($described) aria-describedby="{{ $described }}" @endif @if ($err) aria-invalid="true" @endif></textarea>
                        @elseif ($f['type'] === 'choice')
                            <select id="{{ $id }}" wire:model="values.{{ $f['key'] }}" @if ($f['required']) required aria-required="true" @endif
                                    @if ($described) aria-describedby="{{ $described }}" @endif @if ($err) aria-invalid="true" @endif>
                                <option value="">Choose…</option>
                                @foreach ($f['options'] as $option) <option value="{{ $option }}">{{ $option }}</option> @endforeach
                            </select>
                        @else
                            <input id="{{ $id }}" wire:model="values.{{ $f['key'] }}"
                                   type="{{ ['email' => 'email', 'phone' => 'tel', 'number' => 'number', 'date' => 'date'][$f['type']] ?? 'text' }}"
                                   @if ($f['type'] === 'email') autocomplete="email" maxlength="190" @elseif ($f['type'] === 'phone') autocomplete="tel" maxlength="40" @elseif ($f['type'] === 'number') inputmode="decimal" step="any" @elseif ($f['type'] === 'text') maxlength="300" @endif
                                   @if ($f['type'] === 'text' && preg_match('/\bname\b/i', $f['label'])) autocomplete="name" @endif
                                   @if ($f['required']) required aria-required="true" @endif @if ($described) aria-describedby="{{ $described }}" @endif @if ($err) aria-invalid="true" @endif>
                        @endif
                        @if ($f['help'] !== '') <p class="made-form__help" id="{{ $id }}-help">{{ $f['help'] }}</p> @endif
                        @if ($err) <p class="field__error" id="{{ $id }}-error">{{ $err }}</p> @endif
                    </div>
                @endif
            @endforeach

            <div class="leave-note__honey" aria-hidden="true">
                <label for="mf-{{ $handle }}-website">Leave this empty</label>
                <input id="mf-{{ $handle }}-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <x-guard form="contact" />

            <button type="submit" class="button button--ink" wire:loading.attr="disabled" wire:target="send">
                <span wire:loading.remove wire:target="send">{{ $form->get('button') ?: 'Send' }}</span>
                <span wire:loading wire:target="send">Sending…</span>
            </button>
        </form>
    @endif
</section>
