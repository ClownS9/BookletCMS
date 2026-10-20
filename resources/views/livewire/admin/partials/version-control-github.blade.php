{{-- Settings › Version control: sending the history to GitHub, without the command line. --}}
@php $problem = $status['push_problem'] ?? null; @endphp
<section class="vc-github" aria-labelledby="vc-github-title">
    <h2 id="vc-github-title" class="vc-github__title">A copy on GitHub</h2>

    @error('github') <p class="vc-note vc-note--warn" role="alert" style="margin: 0">{{ $message }}</p> @enderror

    @if (! $connected)
        <p class="vc-github__lede">So the history isn’t only on this computer. Three steps, no commands.</p>

        <form wire:submit="connect" class="vc-steps">
            <div class="vc-step" style="--tone: var(--coral)">
                <span class="vc-step__no" aria-hidden="true">1</span>
                <div class="vc-step__body">
                    <h3>Make an empty repository on GitHub</h3>
                    <p>Choose <strong>Private</strong>, and leave “Add a README”, .gitignore and licence <strong>off</strong>, so it starts empty.</p>
                    <a href="{{ $newRepoUrl }}" target="_blank" rel="noopener" class="btn btn--sm">Open GitHub <span aria-hidden="true">↗</span></a>
                </div>
            </div>

            <div class="vc-step" style="--tone: var(--sun)">
                <span class="vc-step__no" aria-hidden="true">2</span>
                <div class="vc-step__body">
                    <h3><label for="vc-address">Paste its address</label></h3>
                    <p>From the address bar, or the green <strong>Code</strong> button. <code>owner/repository</code> works too.</p>
                    <input id="vc-address" type="text" class="input" wire:model="address" placeholder="https://github.com/you/your-booklet" autocomplete="off" spellcheck="false" style="font-family: var(--mono); font-size: 13px">
                    @error('address') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="vc-step" style="--tone: var(--mint)" x-data="{ how: $wire.entangle('signIn') }">
                <span class="vc-step__no" aria-hidden="true">3</span>
                <div class="vc-step__body">
                    <h3>Let Booklet send to it</h3>
                    <div class="vc-choice" role="radiogroup" aria-label="How Booklet signs in to GitHub">
                        <label><input type="radio" value="token" x-model="how"> <span><b>With a token</b> <em>simplest</em></span></label>
                        <label><input type="radio" value="own" x-model="how"> <span><b>This computer is already signed in</b> (an SSH key or a saved GitHub login)</span></label>
                    </div>

                    <div x-show="how === 'token'" class="vc-token">
                        <ol>
                            <li><a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener">Make a token on GitHub <span aria-hidden="true">↗</span></a> (fine-grained).</li>
                            <li><strong>Repository access:</strong> Only select repositories, then pick the one from step 1.</li>
                            <li><strong>Permissions:</strong> Contents, set to <strong>Read and write</strong>. Nothing else.</li>
                            <li>Generate it, copy it, and paste it here.</li>
                        </ol>
                        <label for="vc-token" class="sr-only">Token</label>
                        <input id="vc-token" type="password" class="input" wire:model="token" placeholder="{{ $tokenHint ? 'Saved token '.$tokenHint.' (paste to replace)' : 'github_pat_…' }}" autocomplete="off" spellcheck="false">
                        @error('token') <p class="field__error">{{ $message }}</p> @enderror
                        <p class="field__hint">Kept encrypted on this server, only ever handed to git, never shown again. It can only reach this one repository.</p>
                    </div>
                    <p x-show="how === 'own'" x-cloak class="field__hint">Use the SSH address (<code>git@github.com:…</code>) if this computer has an SSH key on GitHub. A Mac may ask once to let git use the saved login: choose <strong>Always Allow</strong>.</p>
                </div>
            </div>

            <button type="submit" class="btn btn--ink btn--lg" wire:loading.attr="disabled" wire:target="connect">
                <span wire:loading.remove wire:target="connect">Connect and send</span>
                <span wire:loading wire:target="connect">Connecting…</span>
            </button>
        </form>
    @else
        <div class="vc-linked">
            <div class="vc-linked__where">
                <span class="vc-dot {{ $problem ? '' : 'vc-dot--ok' }}" aria-hidden="true"></span>
                <span>
                    @if ($webUrl)
                        <a href="{{ $webUrl }}" target="_blank" rel="noopener" class="vc-linked__name">{{ $remoteLabel }} <span aria-hidden="true">↗</span></a>
                    @else
                        <code>{{ $remoteUrl }}</code>
                    @endif
                    <span class="vc-linked__meta">
                        @if ($problem) Not sent: see below.
                        @elseif (! empty($status['pushed'])) Last sent {{ \Illuminate\Support\Carbon::parse($status['pushed'])->diffForHumans() }}.
                        @else Connected. @endif
                        @if ($tokenHint) Signs in with token {{ $tokenHint }}. @elseif (str_starts_with((string) $remoteUrl, 'git@')) Signs in with this computer’s SSH key. @else Signs in with this computer’s saved login. @endif
                    </span>
                </span>
            </div>

            @if ($problem === 'diverged')
                <div class="vc-note vc-note--warn">
                    <p><strong>GitHub already has commits this book doesn’t.</strong> Usually a README ticked when the repository was made, or an older history (from before the history here was started again). Booklet won’t mix the two on its own: that’s how files end up with merge marks.</p>
                    <div class="vc-linked__choices">
                        <button type="button" class="btn btn--ink btn--sm" wire:click="replaceRemote"
                                wire:confirm="Replace what’s on GitHub with this book’s history? GitHub’s current commits (and anything only there, like a README made on GitHub) are dropped from it. Your files here aren’t touched.">Replace GitHub’s copy with this book</button>
                        <button type="button" class="btn btn--sm" wire:click="disconnect">Use a different, empty repository</button>
                    </div>
                    <p class="field__hint">Not sure? Choose a different, empty repository: nothing is lost either way.</p>
                </div>
            @elseif ($problem)
                <div class="vc-note vc-note--warn">
                    <p>{{ $status['push_error'] }}</p>
                    @if ($problem === 'signin' || $problem === 'missing')
                        <button type="button" class="btn btn--sm" wire:click="$set('changingToken', true)">Paste a new token</button>
                    @endif
                </div>
            @endif

            @if ($changingToken)
                <form wire:submit="saveNewToken" class="vc-token">
                    <label for="vc-new-token" class="field__label">New token</label>
                    <p class="field__hint" style="margin: 0"><a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener">Make one on GitHub <span aria-hidden="true">↗</span></a>: only this repository, Contents set to Read and write.</p>
                    <input id="vc-new-token" type="password" class="input" wire:model="token" placeholder="github_pat_…" autocomplete="off" spellcheck="false">
                    @error('token') <p class="field__error">{{ $message }}</p> @enderror
                    <div class="vc-linked__choices">
                        <button type="submit" class="btn btn--ink btn--sm">Save token</button>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('changingToken', false)">Cancel</button>
                    </div>
                </form>
            @endif

            <div class="vc-linked__choices">
                <button type="button" class="btn btn--sm" wire:click="pushNow" wire:loading.attr="disabled" wire:target="pushNow">
                    <span wire:loading.remove wire:target="pushNow">Send now</span><span wire:loading wire:target="pushNow">Sending…</span>
                </button>
                @if (! $changingToken && str_starts_with((string) $remoteUrl, 'https://'))
                    <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('changingToken', true)">{{ $tokenHint ? 'Change token' : 'Use a token' }}</button>
                @endif
                <button type="button" class="btn btn--quiet btn--sm vc-linked__off" wire:click="disconnect"
                        wire:confirm="Stop sending to GitHub? The history stays here, and what’s on GitHub stays there. The saved token is deleted.">Disconnect</button>
            </div>
        </div>
    @endif
</section>
