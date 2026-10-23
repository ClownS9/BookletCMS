<div style="display: contents"
     x-data="{ copied: null, copy(text, id) { navigator.clipboard?.writeText(text).then(() => { this.copied = id; setTimeout(() => this.copied = null, 1800) }) } }">
    {{-- Left page: on or off, the keys --}}
    <section class="page page--left page--stack" style="flex-basis: 400px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Settings</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">Content API</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">Your published posts and pages as JSON, so an app or another website can show them. Read-only: nothing sent here can change the site, and drafts never leave.</p>
        </div>

        <label class="nav-switch">
            <input type="checkbox" role="switch" wire:model.live="enabled">
            <span><b>Turn on the content API</b>
                <span class="field__hint">Off: every address under <code>/api/v1</code> answers “not found”.</span></span>
        </label>

        <fieldset @class(['api-keys', 'api-keys--off' => ! $enabled])>
            <legend class="field__label">Keys</legend>
            <p class="field__hint" style="margin: 0 0 10px">One for each app or site, so you can switch one off without the others noticing.</p>

            @if ($fresh)
                <div class="vc-note api-fresh" role="status">
                    <p><strong>Here’s the key.</strong> Copy it now: it won’t be shown again. Keep it out of web pages anyone can read (see the note on the right).</p>
                    <div class="api-fresh__key">
                        <code>{{ $fresh }}</code>
                        <button type="button" class="btn btn--sm" x-on:click="copy(@js($fresh), 'fresh')"><span x-text="copied === 'fresh' ? 'Copied' : 'Copy'">Copy</span></button>
                    </div>
                    <button type="button" class="btn btn--quiet btn--sm" wire:click="forget">I’ve kept it somewhere safe</button>
                </div>
            @endif

            <form wire:submit="make" class="api-make">
                <label for="api-key-name" class="sr-only">What it’s for</label>
                <input id="api-key-name" type="text" class="input" wire:model="name" placeholder="What it’s for: the phone app, Aminah’s site…" maxlength="80">
                <button type="submit" class="btn">Make a key</button>
            </form>
            @error('name') <p class="field__error">{{ $message }}</p> @enderror

            @php $live = $keys->whereNull('revoked_at'); $off = $keys->whereNotNull('revoked_at'); @endphp
            @if ($live->isEmpty())
                <p class="empty-note" style="margin: 4px 0 0">No keys yet.</p>
            @else
                <ul class="api-key-list">
                    @foreach ($live as $k)
                        <li wire:key="key-{{ $k->id }}">
                            <span class="api-key-list__name">{{ $k->name }}</span>
                            <code class="api-key-list__end">bk_…{{ $k->last4 }}</code>
                            <span class="api-key-list__use">
                                @if ($k->last_used_at)
                                    Used {{ $k->last_used_at->diffForHumans() }} · {{ number_format($k->uses) }} {{ str('time')->plural($k->uses) }}
                                @else
                                    Not used yet
                                @endif
                                · made {{ $k->created_at->format('j M Y') }}{{ $k->creator ? ' by '.$k->creator->name : '' }}
                            </span>
                            <button type="button" class="btn btn--quiet btn--sm" wire:click="revoke({{ $k->id }})" wire:confirm="Switch off “{{ $k->name }}”? Anything using it stops getting answers.">Switch off</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($off->isNotEmpty())
                <details class="api-off">
                    <summary>{{ $off->count() }} switched off</summary>
                    <ul class="api-key-list api-key-list--off">
                        @foreach ($off as $k)
                            <li wire:key="off-{{ $k->id }}"><span class="api-key-list__name">{{ $k->name }}</span> <code class="api-key-list__end">bk_…{{ $k->last4 }}</code> <span class="api-key-list__use">Switched off {{ $k->revoked_at->diffForHumans() }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </fieldset>

        <label class="nav-switch">
            <input type="checkbox" role="switch" wire:model.live="openToAll">
            <span><b>Open to everyone, no key needed</b>
                <span class="field__hint">Fine for a public blog: it’s only what’s on the site already. Keys still work and are still counted.</span></span>
        </label>

        <form wire:submit="save" class="field" style="margin: 0">
            <label for="api-origins">Websites whose pages may read it <span class="muted">(for JavaScript in a browser)</span></label>
            <textarea id="api-origins" class="input" rows="3" wire:model="origins" placeholder="https://kopikuih.com&#10;https://app.kopikuih.com" spellcheck="false" style="font-family: var(--mono); font-size: 13px"></textarea>
            @error('origins') <p class="field__error">{{ $message }}</p> @enderror
            <p class="field__hint">One per line. <code>*</code> for any website. Apps and servers don’t need to be listed.</p>
            <button type="submit" class="btn btn--quiet btn--sm" style="align-self: flex-start; margin-top: 6px">Save websites</button>
        </form>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: how to ask --}}
    <section class="page page--right page--stack" style="--ribbon: var(--lilac)">
        <div class="ribbon" aria-hidden="true"></div>
        <div class="ribbon-clear">
            <p class="eyebrow">How to ask</p>
            <h2 class="heading" style="font-size: 28px; margin-top: 6px">Addresses to try</h2>
            <p class="field__hint" style="margin: 8px 0 0">Send the key as <code>Authorization: Bearer bk_…</code>{{ $openToAll ? ', or leave it out' : '' }}. Answers are JSON; lists come 20 at a time with <code>links.next</code> for more.</p>
        </div>

        @php
            $calls = [
                ['/collections/'.$collection.'/entries', 'The newest posts, with title, address, date, author, first lines, cover picture and categories.'],
                ['/collections/'.$collection.'/entries?tag=recipes&limit=5', 'Filtered: by tag or category, ?search=words, ?sort=title, ?content=1 for the whole text.'],
                ['/entries/'.$sampleId, 'One entry in full: the text as Markdown and as HTML, every field, its translations.'],
                ['/find?uri=/about', 'Whatever is at an address on the site.'],
                ['/menus/header', 'A menu, drop-downs included (the seasonal one, when it’s on).'],
                ['/taxonomies/tags/terms', 'Every tag, with how many posts have it.'],
                ['/site', 'The site’s name, tagline, language, logo and accent colour.'],
            ];
        @endphp
        <ol class="api-calls">
            @foreach ($calls as $i => [$path, $about])
                <li>
                    <code class="api-calls__path"><span class="muted">GET</span> {{ $path }}</code>
                    <span class="api-calls__about">{{ $about }}</span>
                </li>
            @endforeach
        </ol>

        @php $curl = "curl ".$base."/collections/".$collection."/entries?limit=3 \\\n  -H \"Authorization: Bearer ".($fresh ?: 'bk_your_key')."\""; @endphp
        <div class="api-example">
            <div class="api-example__head">
                <span class="field__label" style="margin: 0">From a terminal</span>
                <button type="button" class="btn btn--sm" x-on:click="copy(@js($curl), 'curl')"><span x-text="copied === 'curl' ? 'Copied' : 'Copy'">Copy</span></button>
            </div>
            <pre><code>{{ $curl }}</code></pre>
        </div>

        @php $js = "const answer = await fetch('".$base."/collections/".$collection."/entries?limit=3'".($openToAll ? '' : ", {\n  headers: { Authorization: 'Bearer bk_your_key' },\n}").");\nconst { data } = await answer.json();\ndata.forEach(post => console.log(post.title, post.url));"; @endphp
        <div class="api-example">
            <div class="api-example__head">
                <span class="field__label" style="margin: 0">From JavaScript</span>
                <button type="button" class="btn btn--sm" x-on:click="copy(@js($js), 'js')"><span x-text="copied === 'js' ? 'Copied' : 'Copy'">Copy</span></button>
            </div>
            <pre><code>{{ $js }}</code></pre>
        </div>

        <div class="backup-notes">
            <p><strong>Only what readers can see:</strong> published posts and pages. Drafts, scheduled posts, proof copies, comments, subscribers and anything in Settings never leave.</p>
            <p><strong>Keep keys on a server.</strong> A key written into a web page can be read by anyone who opens it. For a page in a browser, list its website above and turn on <b>Open to everyone</b> instead.</p>
            <p>Up to {{ $perMinute }} requests a minute for each key. Answers say so in <code>X-RateLimit-Remaining</code>.</p>
        </div>

        <x-admin.folio side="right" />
    </section>
</div>
