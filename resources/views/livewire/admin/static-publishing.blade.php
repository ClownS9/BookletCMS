<div style="display: contents">
    {{-- Left page: where it goes, and the button --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Settings</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">Publish as plain files</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">The public site as ordinary web pages, pictures and styles, for GitHub Pages, Netlify or any host that serves files. No PHP needed there, and nothing to keep updated.</p>
        </div>

        @error('publish') <p class="vc-note vc-note--warn" role="alert" style="margin: 0">{{ $message }}</p> @enderror

        <form wire:submit="save" class="static-form">
            <fieldset class="static-where">
                <legend class="field__label">Where it goes</legend>
                <label class="static-choice">
                    <input type="radio" name="where" wire:model.live="where" value="zip">
                    <span><b>A .zip to download</b> <span class="field__hint">For Netlify (drag it onto app.netlify.com/drop), or upload it to any host.</span></span>
                </label>
                <label @class(['static-choice', 'static-choice--off' => ! $connected])>
                    <input type="radio" name="where" wire:model.live="where" value="github" @disabled(! $connected)>
                    <span><b>GitHub Pages</b>
                        <span class="field__hint">
                            @if ($connected)
                                Sent to the <code>gh-pages</code> branch of {{ $remoteLabel }}. Your own branches aren’t touched.
                            @else
                                Connect GitHub first, in <a href="{{ route('admin.settings.version-control') }}" wire:navigate>Version control</a>.
                            @endif
                        </span>
                    </span>
                </label>
                @error('github') <p class="field__error">{{ $message }}</p> @enderror
            </fieldset>

            <div class="field">
                <label for="static-address">Published address <span class="muted">(where the files will live)</span></label>
                <input id="static-address" type="text" class="input" wire:model="address" placeholder="{{ $suggested }}" spellcheck="false" style="font-family: var(--mono); font-size: 13px">
                @error('address') <p class="field__error">{{ $message }}</p> @enderror
                <p class="field__hint">Links, pictures and shares are written for this address. Empty: {{ $effective }}.</p>
            </div>

            @if ($where === 'github')
                <div class="field">
                    <label for="static-domain">Your own domain <span class="muted">(optional)</span></label>
                    <input id="static-domain" type="text" class="input" wire:model="domain" placeholder="kopikuih.com" spellcheck="false">
                    @error('domain') <p class="field__error">{{ $message }}</p> @enderror
                    <p class="field__hint">GitHub Pages then answers at this domain (point its DNS to GitHub first).</p>
                </div>
            @endif

            <label class="nav-switch">
                <input type="checkbox" role="switch" wire:model="auto">
                <span><b>Publish again by itself after changes</b>
                    <span class="field__hint">A couple of minutes after things go quiet. Needs the scheduler (see Check-up).</span></span>
            </label>

            <div class="static-actions">
                <button type="button" class="btn btn--ink btn--lg" wire:click="publish" wire:loading.attr="disabled" wire:target="publish">
                    <span wire:loading.remove wire:target="publish">{{ $where === 'github' ? 'Make and send to GitHub' : 'Make the files' }}</span>
                    <span wire:loading wire:target="publish">Making the pages…</span>
                </button>
                <button type="submit" class="btn btn--quiet">Save settings</button>
            </div>
        </form>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: what came out, and what's different --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sky)">
        <div class="ribbon" aria-hidden="true"></div>
        <div class="ribbon-clear">
            <p class="eyebrow">The last printing</p>
            <h2 class="heading" style="font-size: 28px; margin-top: 6px">{{ ! empty($last['at']) ? \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans() : 'Not made yet' }}</h2>
        </div>

        @if (! empty($last['at']))
            <dl class="static-numbers">
                <div><dt>Pages</dt><dd>{{ number_format($last['pages'] ?? 0) }}</dd></div>
                <div><dt>Files</dt><dd>{{ number_format($last['files'] ?? 0) }}</dd></div>
                <div><dt>Size</dt><dd>{{ number_format(($last['bytes'] ?? 0) / 1048576, 1) }} MB</dd></div>
            </dl>
            <p class="field__hint" style="margin: 0">For <code>{{ $last['address'] ?? '' }}</code>.</p>

            @if (! empty($last['error']))
                <p class="vc-note vc-note--warn" style="margin: 0">Not sent to GitHub: {{ $last['error'] }}</p>
            @elseif (! empty($last['sent']))
                <div class="vc-note">
                    <p><strong>Sent to GitHub Pages</strong> {{ \Illuminate\Support\Carbon::parse($last['sent'])->diffForHumans() }}. GitHub puts it online within a minute or two.</p>
                    @if ($pagesSettings)
                        <p class="field__hint" style="margin: 0">The first time: in GitHub, open <a href="{{ $pagesSettings }}" target="_blank" rel="noopener">Settings › Pages <span aria-hidden="true">↗</span></a>, choose <b>Deploy from a branch</b>, then <b>gh-pages</b> and <b>/ (root)</b>, and save.</p>
                    @endif
                    <a href="{{ $last['address'] }}" target="_blank" rel="noopener">Open the published site <span aria-hidden="true">↗</span></a>
                </div>
            @endif

            @if ($hasZip)
                <a href="{{ route('admin.settings.static.download') }}" class="btn" style="align-self: flex-start">Download as .zip</a>
            @endif

            @if (! empty($last['skipped']))
                <p class="vc-note vc-note--warn" style="margin: 0">Left out because they didn’t open: {{ implode(', ', array_slice($last['skipped'], 0, 8)) }}</p>
            @endif
        @else
            <p class="empty-note">Press <b>{{ $where === 'github' ? 'Make and send to GitHub' : 'Make the files' }}</b> and the whole public site is made as files.</p>
        @endif

        <div class="backup-notes">
            <p><strong>Works the same:</strong> every post, page, category and tag, the blog and its pages, pictures in every size, menus and drop-downs, dark reading, the feed and the sitemap. Search still works: it looks through the site in the reader’s browser.</p>
            <p><strong>Left out:</strong> comments, the contact form and the new-posts-by-email sign-up, because they need a server to send to. Visits aren’t counted on the plain copy.</p>
            <p>Keep writing here as usual. Each publish makes the whole site again from what’s published now.</p>
        </div>

        <x-admin.folio side="right" />
    </section>
</div>
