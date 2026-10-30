<form wire:submit="save" style="display: contents">
    {{-- Left page: the basics --}}
    <section class="page page--left page--stack" style="flex-basis: 420px">
        <div>
            <div class="page-head">
                <x-admin.crumbs />
                <p class="eyebrow">{{ \App\Support\AdminNav::chapterLabel() }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Settings') }}</h1>
                <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('The basics about your site.') }}</p>
            </div>
            @if (request()->user()->isAdmin())
                <p style="margin-top: 8px; font-size: 14px; color: var(--faint)">{{ __('Keep a copy of everything in') }} <a href="{{ route('admin.backups') }}" wire:navigate>{{ __('Backups') }}</a>{{ __(', or') }} <a href="{{ route('admin.import') }}" wire:navigate>{{ __('move in from WordPress') }}</a>.</p>
            @endif
        </div>

        <x-admin.card>
            @unless ($canEdit)
                <div class="locked">
                    <x-admin.icon name="lock" size="20" />
                    <span>
                        <strong style="display: block">{{ __('This chapter is locked') }}</strong>
                        <span style="font-size: 14px; color: var(--muted)">{{ __('Only admins can change Settings. You can look, but not edit.') }}</span>
                    </span>
                </div>
            @endunless

            <fieldset @disabled(! $canEdit) style="margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: 20px; min-width: 0">
                <legend class="sr-only">{{ __('Site basics') }}</legend>

                <div class="field">
                    <label for="site-name">{{ __('Site name') }}</label>
                    <input id="site-name" type="text" wire:model="siteName" class="serif" style="font-size: 20px">
                    @error('siteName') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="tagline">{{ __('Tagline') }}</label>
                    <input id="tagline" type="text" wire:model="tagline" placeholder="{{ __('A line under your site name') }}">
                    @error('tagline') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="site-url">{{ __('Site address') }}</label>
                    <input id="site-url" type="url" wire:model="siteUrl" placeholder="{{ __('https://yoursite.com') }}" style="font-family: var(--mono); font-size: 13px">
                    <p class="field__hint">{{ __('Used for links in the feed, the sitemap and when posts are shared.') }}</p>
                    @error('siteUrl') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="two-up">
                    <div class="field">
                        <label for="tz">{{ __('Timezone') }}</label>
                        <select id="tz" wire:model="timezone">
                            @foreach ($timezones as $zone)
                                <option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</option>
                            @endforeach
                        </select>
                        <p class="field__hint">{{ __('When scheduled posts go out.') }}</p>
                        @error('timezone') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="lang">{{ __('Language') }}</label>
                        <select id="lang" wire:model="language">
                            @foreach ($languages as $code => $label)
                                <option value="{{ $code }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <p class="field__hint">{{ __('Of your public site: the words around your writing (buttons, dates, messages to readers) follow it.') }}</p>
                        @error('language') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="field" id="logo">
                    <span class="field__label">{{ __('Logo & icon') }}</span>
                    <div style="display: flex; gap: 18px; align-items: center; flex-wrap: wrap">
                        <span class="logo-slot">
                            @if ($logoUpload)
                                <img src="{{ $logoUpload->temporaryUrl() }}" alt="{{ __('New logo preview') }}">
                            @elseif ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ __('Current logo') }}">
                            @else
                                <span class="muted serif" style="font-size: 13px">{{ __('No logo') }}</span>
                            @endif
                        </span>
                        <span style="display: flex; flex-direction: column; gap: 8px">
                            <span class="field__hint">{{ __('SVG or PNG, square works best. Up to 4 MB.') }}</span>
                            <span style="display: flex; gap: 8px; flex-wrap: wrap">
                                <label class="btn btn--sm" style="position: relative; overflow: hidden">
                                    <span wire:loading.remove wire:target="logoUpload">{{ $logo ? __('Replace') : __('Upload') }}</span>
                                    <span wire:loading wire:target="logoUpload">{{ __('Uploading…') }}</span>
                                    <input type="file" accept="image/*" wire:model="logoUpload" style="position: absolute; inset: 0; opacity: 0; cursor: pointer">
                                </label>
                                @if ($logo)
                                    <button type="button" class="btn btn--quiet btn--sm" wire:click="removeLogo">{{ __('Remove') }}</button>
                                @endif
                            </span>
                        </span>
                    </div>
                    @error('logoUpload') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                {{-- Colours, fonts and layouts live in the cover designer now. --}}
                @php $cover = \App\Support\Look::current(); @endphp
                <div>
                    <p class="field__label" style="margin: 0 0 10px">{{ __('The cover') }}</p>
                    @if (request()->user()->isAdmin())
                        <a href="{{ route('admin.settings.look') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                            <span class="stacy-badge" style="width: 40px; height: 40px; background: {{ $cover['accent'] }}; color: {{ \App\Support\Look::onAccent($cover['accent']) }}; font-family: var(--serif); font-size: 18px" aria-hidden="true">{{ __('Aa') }}</span>
                            <span style="min-width: 0">
                                <span style="display: block; font-size: 15px; font-weight: 500">{{ \App\Support\Look::presetName($cover) }}</span>
                                <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Themes, fonts, colours and layouts, with a live preview') }}</span>
                            </span>
                            <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                        </a>
                    @else
                        <p class="field__hint" style="margin: 0">{{ \App\Support\Look::presetName($cover) }}. An admin can change it.</p>
                    @endif
                </div>
            </fieldset>
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: reading and the machinery --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            <h2 class="heading">{{ __('Reading') }}</h2>

            <fieldset @disabled(! $canEdit) style="margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: 20px; min-width: 0">
                <legend class="sr-only">{{ __('Reading settings') }}</legend>

                <div class="two-up">
                    <div class="field">
                        <label for="homepage">{{ __('Homepage shows') }}</label>
                        <select id="homepage" wire:model="homepage">
                            <option value="posts">{{ __('Latest posts') }}</option>
                            @foreach ($pages as $page)
                                <option value="{{ $page['id'] }}">{{ __('A page: :title', ['title' => $page['title']]) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="per-page">{{ __('Posts per page') }}</label>
                        <input id="per-page" type="number" min="1" max="100" wire:model="perPage">
                        @error('perPage') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="setting-rows">
                    @foreach ([
                        ['comments', 'Comments', 'Readers can leave notes under posts'],
                        ['moderation', 'Hold new comments', 'New notes wait in Comments until approved'],
                        ['stacy', 'Stacy assistant', 'Let Stacy find things and do small jobs in the admin'],
                        ['pageCache', 'Faster pages', 'Keep finished pages for readers; made fresh whenever something changes'],
                    ] as [$key, $label, $hint])
                        <div class="setting-row">
                            <span>
                                <span id="setting-{{ $key }}" style="display: block; font-size: 15px; font-weight: 500">{{ __($label) }}</span>
                                <span style="display: block; font-size: 13px; color: var(--faint)">{{ __($hint) }}</span>
                            </span>
                            <button type="button" class="switch" role="switch" aria-labelledby="setting-{{ $key }}"
                                    aria-checked="{{ $this->{$key} ? 'true' : 'false' }}" wire:click="toggle('{{ $key }}')"></button>
                        </div>
                    @endforeach
                </div>
                @if ($canEdit && $pageCache)
                    <p class="field__hint" style="margin-top: 8px">{{ __('Changed something outside Booklet (a theme file, say)?') }} <button type="button" class="link-button" style="min-height: 0; padding: 0; font-size: 12.5px; text-decoration: underline" wire:click="clearPages">{{ __('Make every page fresh now') }}</button></p>
                @endif
            </fieldset>

            @if (request()->user()->isAdmin())
                @php $mail = \App\Support\MailSettings::status(); @endphp
                <a href="{{ route('admin.settings.email') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--sand); color: #1E1C18" aria-hidden="true"><x-admin.icon name="mail" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Email') }}</span>
                        <span style="display: block; font-size: 13px; color: {{ $mail['state'] === 'off' ? 'var(--danger)' : 'var(--faint)' }}">{{ $mail['state'] === 'off' ? __('Not sending yet. Set up your mail server') : $mail['text'] }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            @if (request()->user()->isAdmin())
                @php $vc = \App\Support\VersionControl::settings(); $vcStatus = \App\Support\VersionControl::status(); @endphp
                <a href="{{ route('admin.settings.version-control') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--{{ ! $vc['on'] ? 'sand' : (! empty($vcStatus['error']) ? 'coral' : 'mint') }}); color: #1E1C18" aria-hidden="true"><x-admin.icon name="history" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Version control') }}</span>
                        <span style="display: block; font-size: 13px; color: {{ ! empty($vcStatus['error']) && $vc['on'] ? 'var(--danger)' : 'var(--faint)' }}">{{ ! $vc['on'] ? __('Off. Keep every change in git, on its own') : (! empty($vcStatus['error']) ? __('The last commit didn’t work') : (! empty($vcStatus['at']) ? 'On. Last commit '.\Illuminate\Support\Carbon::parse($vcStatus['at'])->diffForHumans() : __('On'))) }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            @if (request()->user()->isAdmin())
                @php $static = \App\Support\StaticSite::last(); $staticOn = \App\Support\StaticSite::settings(); @endphp
                <a href="{{ route('admin.settings.static') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--{{ ! empty($static['error']) ? 'coral' : (! empty($static['at']) ? 'sky' : 'sand') }}); color: #1E1C18" aria-hidden="true"><x-admin.icon name="printer" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Publish as plain files') }}</span>
                        <span style="display: block; font-size: 13px; color: {{ ! empty($static['error']) ? 'var(--danger)' : 'var(--faint)' }}">{{ ! empty($static['error']) ? __('The last send to GitHub didn’t work') : (! empty($static['at']) ? 'Made '.\Illuminate\Support\Carbon::parse($static['at'])->diffForHumans().($staticOn['github'] ? ', for GitHub Pages' : '') : __('For GitHub Pages, Netlify or any host')) }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            @if (request()->user()->isAdmin())
                @php $api = \App\Support\ContentApi::settings(); $apiKeys = rescue(fn () => \App\Models\ApiKey::whereNull('revoked_at')->count(), 0, false); @endphp
                <a href="{{ route('admin.settings.api') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--{{ $api['on'] ? 'lilac' : 'sand' }}); color: #1E1C18" aria-hidden="true"><x-admin.icon name="code" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Content API') }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ $api['on'] ? __('On').($api['open'] ? ', '.__('open to everyone') : '').' · '.trans_choice(':count key|:count keys', $apiKeys) : __('Off. Your posts as JSON, for an app or another site') }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            @if (request()->user()->isAdmin())
                @php $hooks = rescue(fn () => \App\Models\Webhook::query()->get(['active', 'failures']), collect(), false); $hooksOn = $hooks->where('active', true); $hooksFailing = $hooksOn->where('failures', '>', 0)->count(); @endphp
                <a href="{{ route('admin.settings.webhooks') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--{{ $hooksFailing ? 'coral' : ($hooksOn->isNotEmpty() ? 'sky' : 'sand') }}); color: #1E1C18" aria-hidden="true"><x-admin.icon name="redirect" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Webhooks') }}</span>
                        <span style="display: block; font-size: 13px; color: {{ $hooksFailing ? 'var(--danger)' : 'var(--faint)' }}">{{ $hooksFailing ? trans_choice(':count webhook isn’t going through|:count webhooks aren’t going through', $hooksFailing) : ($hooksOn->isNotEmpty() ? trans_choice(':count webhook on|:count webhooks on', $hooksOn->count()) : __('Off. Rebuild a static site, tell Slack or Discord, start a Zap')) }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            @if (request()->user()->isAdmin())
                @php $newer = \App\Support\Updates::available(); @endphp
                <a href="{{ route('admin.settings.updates') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--{{ $newer ? 'sun' : 'sand' }}); color: #1E1C18" aria-hidden="true"><x-admin.icon name="history" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Updates') }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ $newer ? __('Booklet :version is out', ['version' => $newer['version']]) : __('This is Booklet :version', ['version' => \App\Support\Updates::current()]) }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            @if (request()->user()->isAdmin())
                @php $health = rescue(fn () => \App\Support\Health::summary(), ['fail' => [], 'warn' => []], true); $hf = count($health['fail']); $hw = count($health['warn']); @endphp
                <a href="{{ route('admin.settings.health') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--{{ $hf ? 'coral' : ($hw ? 'sun' : 'mint') }}); color: #1E1C18" aria-hidden="true"><x-admin.icon name="pulse" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Check-up') }}</span>
                        <span style="display: block; font-size: 13px; color: {{ $hf ? 'var(--danger)' : 'var(--faint)' }}">{{ $hf ? trans_choice(':count thing to fix|:count things to fix', $hf).': '.implode(', ', array_map(fn ($t) => __($t), array_slice($health['fail'], 0, 2))) : ($hw ? trans_choice('Working. :count thing worth a look|Working. :count things worth a look', $hw) : __('The server has everything the book needs')) }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
                @php $away = \App\Support\Upkeep::readersAway(); @endphp
                <a href="{{ route('admin.settings.upkeep') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--{{ $away ? 'sun' : 'sand' }}); color: #1E1C18" aria-hidden="true"><x-admin.icon name="settings" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Upkeep') }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ $away ? __('Readers are seeing “back soon”.') : __('Clear caches, make it quick, remake pictures, tidy the writing') }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            @if (request()->user()->isAdmin())
                @php $on = rescue(fn () => app(\App\Inserts\InsertManager::class)->active()->count(), 0, false); $all = rescue(fn () => app(\App\Inserts\InsertManager::class)->all()->count(), 0, false); $updates = rescue(fn () => app(\App\Inserts\InsertManager::class)->all()->filter(fn ($i) => $i->updateReady())->count(), 0, false); @endphp
                <a href="{{ route('admin.settings.inserts') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px; background: var(--sun); color: #1E1C18" aria-hidden="true"><x-admin.icon name="layers" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Inserts') }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ $all === 0 ? __('Extras for the book: galleries, events…') : __(':on of :all switched on', ['on' => $on, 'all' => $all]) }}@if ($updates) · <strong style="color: var(--ink); font-weight: 600">{{ trans_choice(':count update ready|:count updates ready', $updates) }}</strong>@endif</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('admin.settings.stacy') }}" class="bookplate" style="padding: 12px 16px" wire:navigate>
                    <span class="stacy-badge" style="width: 40px; height: 40px" aria-hidden="true"><x-admin.icon name="stacy" size="18" /></span>
                    <span style="min-width: 0">
                        <span style="display: block; font-size: 15px; font-weight: 500">{{ __('Stacy’s brain') }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Choose AI services, keys and the default model') }}</span>
                    </span>
                    <span aria-hidden="true" class="muted" style="margin-left: auto">→</span>
                </a>
            @endif

            <div>
                <h3 class="eyebrow section-label">{{ __('Where things are kept') }}</h3>
                <p style="font-size: 14px; color: var(--muted); margin-bottom: 10px">{{ __('Set per part in') }} <code>{{ __('.env') }}</code>{{ __('. Move content between them with') }} <code>{{ __('booklet:export') }}</code> {{ __('and') }} <code>{{ __('booklet:import') }}</code>.</p>
                <ul class="drivers">
                    @foreach ($drivers as $group => $driver)
                        <li><span>{{ str($group)->headline() }}</span><span class="sticker sticker--{{ $driver === 'file' ? 'sun' : 'sky' }}" style="--tilt: {{ $loop->even ? '-2deg' : '2deg' }}">{{ $driver === 'file' ? __('Files') : ($driver === 'database' ? __('Database') : class_basename($driver)) }}</span></li>
                    @endforeach
                </ul>
            </div>

            <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
                @if ($canEdit)
                    <button type="submit" class="btn btn--ink btn--lg">
                        <span wire:loading.remove wire:target="save">{{ $saved ? __('Saved') : __('Save settings') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </button>
                @else
                    <span></span>
                @endif
                <x-admin.folio side="right" style="margin: 0; padding: 0" />
            </div>
        </x-admin.card>

    </section>
</form>
