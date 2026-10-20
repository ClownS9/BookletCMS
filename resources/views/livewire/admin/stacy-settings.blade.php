<div style="display: contents">
    {{-- Left page: the services --}}
    <section class="page page--left" style="flex-basis: 360px">
        <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Settings</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
        <h1 class="display display--page" style="margin-top: 8px">Stacy’s brain</h1>
        <p class="lede" style="margin: 12px 0 14px; font-size: 15px">Choose which AI services Stacy may think with. Each person picks their favourite model in their account.</p>
        <a href="{{ route('admin.settings.stacy.behaviour') }}" class="behaviour-link" wire:navigate>
            <span><strong>How Stacy behaves</strong><small>Answer length, tone, house style, what she may change, daily limits</small></span>
            <span aria-hidden="true">→</span>
        </a>

        <ul class="picks">
            @foreach ($services as $key => $service)
                @php $r = $records->get($key); @endphp
                <li wire:key="svc-{{ $key }}">
                    <button type="button" class="pick" wire:click="pick('{{ $key }}')" aria-pressed="{{ $selected === $key ? 'true' : 'false' }}" style="align-items: center">
                        <span @class(['svc-light', 'svc-light--on' => $r?->isReady()]) aria-hidden="true"></span>
                        <span style="flex: 1 1 auto; min-width: 0">
                            <span class="pick__title" style="font-size: 18px">{{ $service['name'] }}</span>
                            <span class="pick__meta">
                                @if ($r?->isReady())
                                    On{{ $r->is_default ? ' · default' : '' }} · {{ $r->default_model }}
                                @elseif ($r?->enabled)
                                    Needs a key or model
                                @else
                                    Off
                                @endif
                            </span>
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: one service --}}
    <form wire:submit="save" class="page page--right page--stack" style="--ribbon: #1E1C18">
        <div class="ribbon" aria-hidden="true"></div>

        <div class="ribbon-clear">
            <h2 class="heading">{{ $info['name'] }}</h2>
            <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">{{ $info['note'] }}</p>
        </div>

        <div class="setting-rows">
            <div class="setting-row">
                <span>
                    <span id="svc-enabled" style="display: block; font-size: 15px; font-weight: 500">Let Stacy use {{ $info['name'] }}</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">Switched-on services appear in everyone’s account to choose from.</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="svc-enabled"
                        aria-checked="{{ $enabled ? 'true' : 'false' }}" wire:click="$toggle('enabled')"></button>
            </div>
        </div>

        @if ($info['needs_key'] || $selected === 'custom')
            <div class="field">
                <label for="api-key">API key</label>
                <input id="api-key" type="password" wire:model="apiKey" autocomplete="off"
                       placeholder="{{ $record->maskedKey() ? 'Saved: '.$record->maskedKey().' · type to replace' : 'Paste the key here' }}">
                @error('apiKey') <p class="field__error">{{ $message }}</p> @enderror
                @if ($record->exists && $record->keyLost())
                    <p class="field__error">The saved key can’t be read any more (the site’s APP_KEY changed). Paste it again and save.</p>
                @endif
                <p class="field__hint">
                    Stored encrypted in the database, never in your content files.
                    @if ($info['key_url']) <a href="{{ $info['key_url'] }}" target="_blank" rel="noopener">Get a key →</a> @endif
                    @if ($record->maskedKey()) · <button type="button" class="link-button" style="min-height: 0; text-decoration: underline; font-size: 12px" wire:click="forgetKey" wire:confirm="Remove the saved key?">Remove saved key</button> @endif
                </p>
            </div>
        @elseif ($info['key_url'])
            <p class="field__hint" style="font-size: 14px">No key needed. <a href="{{ $info['key_url'] }}" target="_blank" rel="noopener">Install Ollama →</a>, then run a model that supports tools.</p>
        @endif

        <div class="field">
            <label for="base-url">Address</label>
            <input id="base-url" type="url" wire:model="baseUrl" placeholder="{{ $info['base_url'] ?: 'https://your-service.example/v1' }}" style="font-family: var(--mono); font-size: 13px">
            @error('baseUrl') <p class="field__error">{{ $message }}</p> @enderror
            <p class="field__hint">{{ $info['base_url'] ? 'Leave empty to use the usual address.' : 'The service’s OpenAI-compatible address, ending in /v1.' }}</p>
        </div>

        <div class="field">
            <label for="default-model">Default model</label>
            <div style="display: flex; gap: 8px; flex-wrap: wrap">
                <input id="default-model" type="text" list="model-list" wire:model="defaultModel" placeholder="Type or pick a model" style="flex: 1 1 260px; font-family: var(--mono); font-size: 13px">
                <datalist id="model-list">
                    @foreach ($models as $model)
                        <option value="{{ $model }}"></option>
                    @endforeach
                </datalist>
                <button type="button" class="btn" wire:click="fetchModels" wire:loading.attr="disabled" wire:target="fetchModels">
                    <span wire:loading.remove wire:target="fetchModels">Fetch models</span>
                    <span wire:loading wire:target="fetchModels">Asking…</span>
                </button>
            </div>
            @error('defaultModel') <p class="field__error">{{ $message }}</p> @enderror
            <p class="field__hint">
                @if ($record->models_fetched_at)
                    {{ count($record->models ?? []) }} models listed {{ $record->models_fetched_at->diffForHumans() }}.
                @else
                    “Fetch models” asks the service what it offers (it needs the key above).
                @endif
                Stacy works best with models that support tool use.
            </p>
        </div>

        @if ($notice)
            <p @class(['notice', 'notice--error' => $noticeIsError]) role="status">{{ $notice }}</p>
        @endif

        <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
            <div style="display: flex; gap: 10px; flex-wrap: wrap">
                <button type="submit" class="btn btn--ink btn--lg">Save</button>
                <button type="button" class="btn" wire:click="test" wire:loading.attr="disabled" wire:target="test">
                    <span wire:loading.remove wire:target="test">Test</span>
                    <span wire:loading wire:target="test">Testing…</span>
                </button>
                @if ($record->exists && $record->enabled && ! $record->is_default)
                    <button type="button" class="btn btn--quiet" wire:click="makeDefault">Make default</button>
                @endif
            </div>
            <x-admin.folio side="right" style="margin: 0; padding: 0" />
        </div>
    </form>
</div>
