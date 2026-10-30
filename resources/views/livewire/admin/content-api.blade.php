<div style="display: contents"
     x-data="{ copied: null, copy(text, id) { navigator.clipboard?.writeText(text).then(() => { this.copied = id; setTimeout(() => this.copied = null, 1800) }) } }">
    {{-- Left page: on or off, the keys --}}
    <section class="page page--left page--stack" style="flex-basis: 400px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Content API') }}</h1>
            </div>
            <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('Your published posts and pages as JSON, so an app or another website can show them. Read-only: nothing sent here can change the site, and drafts never leave.') }}</p>
        </div>

        <label class="nav-switch">
            <input type="checkbox" role="switch" wire:model.live="enabled">
            <span><b>{{ __('Turn on the content API') }}</b>
                <span class="field__hint">{{ __('Off: every address under') }} <code>{{ __('/api/v1') }}</code> {{ __('answers “not found”.') }}</span></span>
        </label>

        <fieldset @class(['api-keys', 'api-keys--off' => ! $enabled])>
            <legend class="field__label">{{ __('Keys') }}</legend>
            <p class="field__hint" style="margin: 0 0 10px">{{ __('One for each app or site, so you can switch one off without the others noticing.') }}</p>

            @if ($fresh)
                <div class="vc-note api-fresh" role="status">
                    <p><strong>{{ __('Here’s the key.') }}</strong> {{ __('Copy it now: it won’t be shown again. Keep it out of web pages anyone can read (see the note on the right).') }}</p>
                    <div class="api-fresh__key">
                        <code>{{ $fresh }}</code>
                        <button type="button" class="btn btn--sm" x-on:click="copy(@js($fresh), 'fresh')"><span x-text="copied === 'fresh' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button>
                    </div>
                    <button type="button" class="btn btn--quiet btn--sm" wire:click="forget">{{ __('I’ve kept it somewhere safe') }}</button>
                </div>
            @endif

            <form wire:submit="make" class="api-make">
                <label for="api-key-name" class="sr-only">{{ __('What it’s for') }}</label>
                <input id="api-key-name" type="text" class="input" wire:model="name" placeholder="{{ __('What it’s for: the phone app, Aminah’s site…') }}" maxlength="80">
                <button type="submit" class="btn">{{ __('Make a key') }}</button>
            </form>
            @error('name') <p class="field__error">{{ $message }}</p> @enderror

            @php $live = $keys->whereNull('revoked_at'); $off = $keys->whereNotNull('revoked_at'); @endphp
            @if ($live->isEmpty())
                <p class="empty-note" style="margin: 4px 0 0">{{ __('No keys yet.') }}</p>
            @else
                <ul class="api-key-list">
                    @foreach ($live as $k)
                        <li wire:key="key-{{ $k->id }}">
                            <span class="api-key-list__name">{{ $k->name }}</span>
                            <code class="api-key-list__end">bk_…{{ $k->last4 }}</code>
                            <span class="api-key-list__use">
                                @if ($k->last_used_at)
                                    {{ __('Used :when', ['when' => $k->last_used_at->diffForHumans()]) }} · {{ trans_choice(':count time|:count times', $k->uses, ['count' => number_format($k->uses)]) }}
                                @else
                                    {{ __('Not used yet') }}
                                @endif
                                · {{ $k->creator ? __('made :date by :name', ['date' => $k->created_at->translatedFormat('j M Y'), 'name' => $k->creator->name]) : __('made :date', ['date' => $k->created_at->translatedFormat('j M Y')]) }}
                            </span>
                            <button type="button" class="btn btn--quiet btn--sm" wire:click="revoke({{ $k->id }})" wire:confirm="{{ __('Switch off “:name”? Anything using it stops getting answers.', ['name' => $k->name]) }}">{{ __('Switch off') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($off->isNotEmpty())
                <details class="api-off">
                    <summary>{{ trans_choice(':count switched off|:count switched off', $off->count()) }}</summary>
                    <ul class="api-key-list api-key-list--off">
                        @foreach ($off as $k)
                            <li wire:key="off-{{ $k->id }}"><span class="api-key-list__name">{{ $k->name }}</span> <code class="api-key-list__end">bk_…{{ $k->last4 }}</code> <span class="api-key-list__use">{{ __('Switched off :when', ['when' => $k->revoked_at->diffForHumans()]) }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </fieldset>

        <label class="nav-switch">
            <input type="checkbox" role="switch" wire:model.live="openToAll">
            <span><b>{{ __('Open to everyone, no key needed') }}</b>
                <span class="field__hint">{{ __('Fine for a public blog: it’s only what’s on the site already. Keys still work and are still counted.') }}</span></span>
        </label>

        <form wire:submit="save" class="field" style="margin: 0">
            <label for="api-origins">{{ __('Websites whose pages may read it') }} <span class="muted">{{ __('(for JavaScript in a browser)') }}</span></label>
            <textarea id="api-origins" class="input" rows="3" wire:model="origins" placeholder="{{ __('https://kopikuih.com https://app.kopikuih.com') }}" spellcheck="false" style="font-family: var(--mono); font-size: 13px"></textarea>
            @error('origins') <p class="field__error">{{ $message }}</p> @enderror
            <p class="field__hint">{{ __('One per line.') }} <code>*</code> {{ __('for any website. Apps and servers don’t need to be listed.') }}</p>
            <button type="submit" class="btn btn--quiet btn--sm" style="align-self: flex-start; margin-top: 6px">{{ __('Save websites') }}</button>
        </form>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: how to ask --}}
    <section class="page page--right page--stack" style="--ribbon: var(--lilac)">
        <div class="ribbon" aria-hidden="true"></div>
        <div class="ribbon-clear">
            <p class="eyebrow">{{ __('How to ask') }}</p>
            <h2 class="heading" style="font-size: 28px; margin-top: 6px">{{ __('Addresses to try') }}</h2>
            <p class="field__hint" style="margin: 8px 0 0">{{ __('Send the key as') }} <code>{{ __('Authorization: Bearer bk_…') }}</code>{{ $openToAll ? __(', or leave it out') : '' }}. {{ __('Answers are JSON; lists come 20 at a time with') }} <code>links.next</code> {{ __('for more.') }}</p>
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
                    <code class="api-calls__path"><span class="muted">{{ __('GET') }}</span> {{ $path }}</code>
                    <span class="api-calls__about">{{ __($about) }}</span>
                </li>
            @endforeach
        </ol>

        @php $curl = "curl ".$base."/collections/".$collection."/entries?limit=3 \\\n  -H \"Authorization: Bearer ".($fresh ?: 'bk_your_key')."\""; @endphp
        <div class="api-example">
            <div class="api-example__head">
                <span class="field__label" style="margin: 0">{{ __('From a terminal') }}</span>
                <button type="button" class="btn btn--sm" x-on:click="copy(@js($curl), 'curl')"><span x-text="copied === 'curl' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button>
            </div>
            <pre><code>{{ $curl }}</code></pre>
        </div>

        @php $js = "const answer = await fetch('".$base."/collections/".$collection."/entries?limit=3'".($openToAll ? '' : ", {\n  headers: { Authorization: 'Bearer bk_your_key' },\n}").");\nconst { data } = await answer.json();\ndata.forEach(post => console.log(post.title, post.url));"; @endphp
        <div class="api-example">
            <div class="api-example__head">
                <span class="field__label" style="margin: 0">{{ __('From JavaScript') }}</span>
                <button type="button" class="btn btn--sm" x-on:click="copy(@js($js), 'js')"><span x-text="copied === 'js' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button>
            </div>
            <pre><code>{{ $js }}</code></pre>
        </div>

        <div class="backup-notes">
            <p><strong>{{ __('Only what readers can see:') }}</strong> {{ __('published posts and pages. Drafts, scheduled posts, proof copies, comments, subscribers and anything in Settings never leave.') }}</p>
            <p><strong>{{ __('Keep keys on a server.') }}</strong> {{ __('A key written into a web page can be read by anyone who opens it. For a page in a browser, list its website above and turn on') }} <b>{{ __('Open to everyone') }}</b> {{ __('instead.') }}</p>
            <p>{{ __('Up to :perminute requests a minute for each key. Answers say so in', ['perminute' => $perMinute]) }} <code>{{ __('X-RateLimit-Remaining') }}</code>.</p>
        </div>

        <x-admin.folio side="right" />
    </section>
</div>
