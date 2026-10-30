{{-- A menu row's drawer: everything about one item, opened with "Edit". --}}
<div class="nav-drawer" id="drawer-{{ $row['key'] }}" wire:key="drawer-{{ $row['key'] }}">
    @if ($row['problems'] !== [])
        <ul class="nav-drawer__problems">
            @foreach ($row['problems'] as $p)
                <li class="nav-drawer__problem nav-drawer__problem--{{ $p['level'] }}">{{ __($p['text']) }}</li>
            @endforeach
        </ul>
    @endif
    <div class="nav-drawer__grid">
        <div class="field">
            <label for="label-{{ $row['key'] }}">{{ __('Label') }}</label>
            <input id="label-{{ $row['key'] }}" type="text" value="{{ __($row['label']) }}" maxlength="80"
                   wire:change="rename({{ $row['index'] }}, $event.target.value)">
            @if ($row['kind'] === 'Page') <p class="field__hint">{{ __('Leave empty to use the page\'s own title.') }}</p> @endif
        </div>

        @if ($row['kind'] === 'Link')
            <div class="field">
                <label for="url-{{ $row['key'] }}">{{ __('Link') }}</label>
                <input id="url-{{ $row['key'] }}" type="text" value="{{ $row['url'] }}" style="font-family: var(--mono); font-size: 13px"
                       wire:change="setOption({{ $row['index'] }}, 'url', $event.target.value)">
                @error('rows.'.$row['index'].'.url') <p class="field__error">{{ $message }}</p> @enderror
            </div>
        @else
            <div class="field">
                <span class="field__label">{{ __('Page') }}</span>
                <p class="nav-drawer__fact">{{ $row['detail'] ?: '—' }}@if ($row['hidden']) · not published, so it stays off the site @endif</p>
            </div>
        @endif
    </div>

    @if ($row['canFollow'] || $row['follow'])
        <label class="nav-switch">
            <input type="checkbox" role="switch" @checked($row['follow']) wire:change="setOption({{ $row['index'] }}, 'follow', $event.target.checked)">
            <span>
                <b>{{ __('Bring its sub-pages along') }}</b>
                <span class="field__hint">{{ __('The pages under it in Pages drop down from it, new ones too, in the same order.') }}</span>
            </span>
        </label>
        @if ($row['follow'] && $row['followed'] !== [])
            <ul class="nav-ghosts" aria-label="{{ __('Pages that come along') }}">
                @foreach ($row['followed'] as $ghost)
                    <li @class(['nav-ghost--hidden' => $ghost['hidden']])>{{ __($ghost['label']) }}</li>
                @endforeach
            </ul>
        @endif
    @endif

    @if ($row['depth'] === 0)
        <label class="nav-switch">
            <input type="checkbox" role="switch" @checked($row['mega']) wire:change="setOption({{ $row['index'] }}, 'mega', $event.target.checked)">
            <span>
                <b>{{ __('Open as a wide panel') }}</b>
                <span class="field__hint">{{ __('Instead of a small list, a panel across the page: what\'s under it in columns, with a picture and a few words if you like.') }}</span>
            </span>
        </label>

        @if ($row['mega'])
            <div class="nav-mega">
                <div class="field">
                    <label for="blurb-{{ $row['key'] }}">{{ __('A few words') }} <span class="muted">{{ __('(optional)') }}</span></label>
                    <textarea id="blurb-{{ $row['key'] }}" rows="2" maxlength="240" placeholder="{{ __('e.g. Everything we make, in small batches.') }}"
                              wire:change="setOption({{ $row['index'] }}, 'blurb', $event.target.value)">{{ __($row['blurb']) }}</textarea>
                </div>

                <div class="field">
                    <span class="field__label">{{ __('Picture') }} <span class="muted">{{ __('(optional)') }}</span></span>
                    @if ($pictures === [])
                        <p class="field__hint">{{ __('No pictures in') }} <a href="{{ route('admin.media') }}" wire:navigate>{{ __('Media') }}</a> {{ __('yet.') }}</p>
                    @else
                        <div class="nav-pictures" role="radiogroup" aria-label="{{ __('Picture for the panel') }}">
                            <button type="button" role="radio" aria-checked="{{ $row['picture'] === '' ? 'true' : 'false' }}" class="nav-picture nav-picture--none"
                                    wire:click="setOption({{ $row['index'] }}, 'picture', '')">{{ __('None') }}</button>
                            @foreach ($pictures as $picture)
                                <button type="button" role="radio" aria-checked="{{ $row['picture'] === $picture['path'] ? 'true' : 'false' }}" class="nav-picture"
                                        wire:click="setOption({{ $row['index'] }}, 'picture', @js($picture['path']))" aria-label="{{ $picture['alt'] ?: basename($picture['path']) }}">
                                    <img src="{{ $picture['url'] }}" alt="" loading="lazy">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <label class="nav-switch nav-switch--small">
                    <input type="checkbox" role="switch" @checked($row['latest']) wire:change="setOption({{ $row['index'] }}, 'latest', $event.target.checked)">
                    <span><b>{{ __('Show the three newest posts') }}</b></span>
                </label>
            </div>
        @endif
    @endif

    <div class="nav-drawer__foot">
        <div class="nav-drawer__moves" role="group" aria-label="Move {{ __($row['label']) }}">
            <button type="button" class="btn btn--quiet btn--sm" wire:click="up({{ $row['index'] }})" @disabled($row['first'])>{{ __('↑ Up') }}</button>
            <button type="button" class="btn btn--quiet btn--sm" wire:click="down({{ $row['index'] }})" @disabled($row['last'])>{{ __('↓ Down') }}</button>
            <button type="button" class="btn btn--quiet btn--sm" wire:click="nest({{ $row['index'] }})" @disabled($row['first'] || $row['depth'] >= $maxDepth)>{{ __('→ Tuck under the one above') }}</button>
            @if ($row['depth'] > 0)
                <button type="button" class="btn btn--quiet btn--sm" wire:click="lift({{ $row['index'] }})">{{ __('← Up a level') }}</button>
            @endif
        </div>
        <button type="button" class="btn btn--quiet btn--sm nav-drawer__remove" wire:click="remove({{ $row['index'] }})">
            Remove @if ($row['children']) <span class="muted">{{ __('(what\'s under it moves up)') }}</span> @endif
        </button>
    </div>
</div>
