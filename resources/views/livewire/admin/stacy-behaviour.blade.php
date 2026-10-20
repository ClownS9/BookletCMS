<form wire:submit="save" style="display: contents">
    {{-- Left page: how she answers --}}
    <section class="page page--left page--stack" style="flex-basis: 420px">
        <div>
            <a href="{{ route('admin.settings.stacy') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Stacy’s brain</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">How Stacy behaves</h1>
            <p class="lede" style="margin-top: 12px; font-size: 15px">How long she answers, how she sounds, and what she remembers. Changes apply from her next answer.</p>
        </div>

        <fieldset class="engine-pick">
            <legend class="field__label">Answer length</legend>
            <div class="choice-grid">
                @foreach ($lengths as $tokens => [$label, $hint])
                    <label class="engine-option">
                        <input type="radio" name="length" value="{{ $tokens }}" wire:model.live="length">
                        <span><strong>{{ $label }}</strong><small>{{ $hint }} Up to {{ number_format($tokens) }} tokens.</small></span>
                    </label>
                @endforeach
                <label class="engine-option">
                    <input type="radio" name="length" value="custom" wire:model.live="length">
                    <span><strong>Custom</strong><small>Your own number.</small></span>
                </label>
            </div>
            @if ($length === 'custom')
                <div class="field" style="margin-top: 6px">
                    <label for="custom-tokens">Longest answer, in tokens</label>
                    <input id="custom-tokens" type="number" min="200" max="16000" step="100" wire:model="customTokens" style="max-width: 200px">
                    <p class="field__hint">Between 200 and 16,000. A token is about three-quarters of a word. Services reserve credit for the full amount, so lower is cheaper.</p>
                    @error('customTokens') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            @endif
        </fieldset>

        <fieldset class="engine-pick">
            <legend class="field__label">Creativity</legend>
            <div class="choice-grid">
                @foreach ($creativity as $value => [$label, $hint])
                    <label class="engine-option">
                        <input type="radio" name="temperature" value="{{ $value }}" wire:model="temperature">
                        <span><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="engine-pick">
            <legend class="field__label">Tone</legend>
            <div class="choice-grid">
                @foreach ($tones as $value => [$label, $hint])
                    <label class="engine-option">
                        <input type="radio" name="tone" value="{{ $value }}" wire:model="tone">
                        <span><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="field">
            <label for="language">Answer in</label>
            <input id="language" type="text" wire:model="language" placeholder="Whatever language they write in" list="languages" style="max-width: 320px">
            <datalist id="languages">
                @foreach (['English', 'Malay', 'Indonesian', 'Chinese', 'Tamil', 'Arabic', 'French', 'Spanish', 'Japanese'] as $lang)
                    <option value="{{ $lang }}"></option>
                @endforeach
            </datalist>
            <p class="field__hint">Leave empty and she replies in the language each person uses.</p>
            @error('language') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="instructions">House style</label>
            <textarea id="instructions" wire:model="instructions" rows="4" maxlength="2000"
                      placeholder="For example: We’re a bakery in Bandar. Call readers “friends”. Use British spelling. Never promise delivery times."></textarea>
            <p class="field__hint">Anything she should always know or follow, in plain words.</p>
            @error('instructions') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="two-up">
            <div class="field">
                <label for="memory">Remembers</label>
                <select id="memory" wire:model="memory">
                    @foreach ([6, 10, 20, 30, 40, 60] as $n)
                        <option value="{{ $n }}">The last {{ $n }} messages</option>
                    @endforeach
                </select>
                <p class="field__hint">Fewer is cheaper; more keeps long chats on track.</p>
            </div>
            <div class="field">
                <label for="steps">Look-ups per question</label>
                <select id="steps" wire:model="steps">
                    @foreach ([2, 4, 6, 8, 10, 12] as $n)
                        <option value="{{ $n }}">Up to {{ $n }}</option>
                    @endforeach
                </select>
                <p class="field__hint">How many times she may check the book before answering.</p>
            </div>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: what she may do, and for whom --}}
    <section class="page page--right page--stack" style="--ribbon: #1E1C18">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear">
            <h2 class="heading">What she may do</h2>
            <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">She always asks first, and only does what the person could do themselves.</p>
        </div>

        <div class="setting-rows">
            @foreach ([
                ['canChange', 'Make changes', 'Switch off and Stacy only finds, explains and takes you places.'],
                ['canPublish', 'Publish and schedule', 'Publish now, schedule, or take things off the site.'],
                ['canEdit', 'Edit details', 'Titles, standfirsts, search text, tags, alt text and the site’s menus.'],
                ['canDraft', 'Start drafts', 'New posts and pages (with an outline if asked), and translated copies of an entry.'],
                ['canComments', 'Handle comments', 'Approve, mark as spam, delete, and reply to readers.'],
                ['canInserts', 'Use inserts', $skills->isEmpty()
                    ? 'Changes that inserts teach her. None of the inserts switched on teach her anything yet.'
                    : 'Changes that inserts teach her (below). What they let her look up is always fine.'],
            ] as [$key, $label, $hint])
                @php $off = $key !== 'canChange' && ! $canChange; @endphp
                <div @class(['setting-row', 'is-muted' => $off]) wire:key="perm-{{ $key }}">
                    <span>
                        <span id="perm-{{ $key }}" style="display: block; font-size: 15px; font-weight: 500">{{ $label }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ $hint }}</span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-labelledby="perm-{{ $key }}"
                            aria-checked="{{ $this->{$key} && ! $off ? 'true' : 'false' }}" wire:click="toggle('{{ $key }}')" @disabled($off)></button>
                </div>
            @endforeach
        </div>

        @if ($skills->isNotEmpty())
            <div class="skill-list">
                <h3 class="eyebrow section-label">What inserts teach her</h3>
                @foreach ($skills as $insertName => $list)
                    <div>
                        <p class="skill-list__insert">{{ $insertName }}</p>
                        <ul>
                            @foreach ($list as $skill)
                                <li @class(['is-muted' => $skill['changes'] && (! $canChange || ! $canInserts)])>
                                    <span class="sticker sticker--{{ $skill['changes'] ? 'coral' : 'sky' }}" style="--tilt: 0deg; font-size: 11px; padding: 1px 7px">{{ $skill['changes'] ? 'Changes' : 'Looks up' }}</span>
                                    <span>{{ $skill['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif

        <h2 class="heading heading--sub" style="margin-top: 8px">Who and how much</h2>

        <fieldset class="engine-pick">
            <legend class="field__label">Who can talk to Stacy</legend>
            <div class="choice-grid choice-grid--three">
                @foreach (['everyone' => 'Everyone', 'editors' => 'Editors & admins', 'admins' => 'Admins only'] as $value => $label)
                    <label class="engine-option">
                        <input type="radio" name="who" value="{{ $value }}" wire:model="who">
                        <span><strong>{{ $label }}</strong></span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="field">
            <label for="daily-limit">Questions per person, per day</label>
            <input id="daily-limit" type="number" min="0" max="10000" wire:model="dailyLimit" style="max-width: 160px">
            <p class="field__hint">0 means no limit. Handy for keeping AI costs predictable.</p>
            @error('dailyLimit') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="setting-rows">
            <div class="setting-row">
                <span>
                    <span id="natural-voice" style="display: block; font-size: 15px; font-weight: 500">Natural voice</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">Her human-sounding voice uses credit with OpenRouter or OpenAI. Off: the browser’s own voice, free.</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="natural-voice" aria-checked="{{ $naturalVoice ? 'true' : 'false' }}" wire:click="toggle('naturalVoice')"></button>
            </div>
            <div class="setting-row">
                <span>
                    <span id="show-model" style="display: block; font-size: 15px; font-weight: 500">Show which model answered</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">A small line under each answer, e.g. “OpenRouter · deepseek/deepseek-chat”.</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="show-model" aria-checked="{{ $showModel ? 'true' : 'false' }}" wire:click="toggle('showModel')"></button>
            </div>
        </div>

        <h2 class="heading heading--sub" style="margin-top: 8px">Saying hello</h2>
        <div class="setting-rows">
            <div class="setting-row">
                <span>
                    <span id="greet" style="display: block; font-size: 15px; font-weight: 500">Greet people when they arrive</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">When someone signs in, or comes back after a few hours, she says hello and mentions what’s waiting. Each person can turn it off for themselves.</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="greet" aria-checked="{{ $greet ? 'true' : 'false' }}" wire:click="toggle('greet')"></button>
            </div>
            <div @class(['setting-row', 'is-muted' => ! $greet])>
                <span>
                    <span id="greet-sound" style="display: block; font-size: 15px; font-weight: 500">Play a sound</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">A soft chime as she appears, little paper ticks as she writes, and a quiet sigh when she tucks away.</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="greet-sound" aria-checked="{{ $greetSound && $greet ? 'true' : 'false' }}" wire:click="toggle('greetSound')" @disabled(! $greet)></button>
            </div>
            <div @class(['setting-row', 'is-muted' => ! $greet])>
                <span>
                    <span id="greet-aloud" style="display: block; font-size: 15px; font-weight: 500">Say it out loud</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">Only for people who have spoken answers on. Her natural voice uses a little credit each time.</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="greet-aloud" aria-checked="{{ $greetAloud && $greet ? 'true' : 'false' }}" wire:click="toggle('greetAloud')" @disabled(! $greet)></button>
            </div>
        </div>

        <p class="usage-note">
            <strong>{{ number_format($usage['today']) }}</strong> {{ str('question')->plural($usage['today']) }} today ·
            <strong>{{ number_format($usage['week']) }}</strong> this week, from {{ $usage['people'] }} {{ str('person')->plural($usage['people']) }}
        </p>

        <div style="margin-top: auto; padding-top: 18px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
            <div style="display: flex; gap: 10px; flex-wrap: wrap">
                <button type="submit" class="btn btn--ink btn--lg">
                    <span wire:loading.remove wire:target="save">Save</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
                <button type="button" class="btn btn--quiet" wire:click="resetToDefaults"
                        wire:confirm="Put every setting on this page back to how it started? (Nothing is saved until you press Save.)">Back to defaults</button>
            </div>
            <x-admin.folio side="right" style="margin: 0; padding: 0" />
        </div>
    </section>
</form>
