<div style="display: contents">
    {{-- Left page: everything, in your order --}}
    <section class="page page--wide-left page--stack">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.account.yours') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Make it yours') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ __('Ex libris · just for you') }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Your menu') }}</h1>
            </div>
            <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('Put each chapter and tool where you’ll reach for it. Only you see these changes, and what you’re not allowed to open never shows.') }}</p>
            @if (\App\Support\YourBook::of(request()->user())['layout'] === 'dashboard')
                <p class="field__hint" style="margin-top: 8px">{{ __('In the Dashboard layout, stickers are listed under Content in the sidebar, and side tabs under Tools.') }}</p>
            @endif
        </div>

        <div class="menu-edit" role="list" aria-label="{{ __('Your menu') }}">
            <div class="menu-edit__head" aria-hidden="true"><span></span><span>{{ __('Name') }}</span><span>{{ __('Where it lives') }}</span><span>{{ __('Order') }}</span></div>
            @foreach ($items as $i => $item)
                <div role="listitem" @class(['menu-edit__row', 'is-hidden' => $item['where'] === 'hidden']) wire:key="mi-{{ $item['key'] }}">
                    <button type="button" class="menu-edit__tone" style="--tone: var(--{{ $item['tone'] }})" wire:click="recolour('{{ $item['key'] }}')"
                            aria-label="{{ __('Colour of :name. Press for the next colour.', ['name' => $item['label']]) }}"></button>
                    <div class="menu-edit__name">
                        <label for="mi-name-{{ $item['key'] }}" class="sr-only">{{ __('Name for :name', ['name' => $item['original']]) }}</label>
                        <input id="mi-name-{{ $item['key'] }}" type="text" maxlength="40" wire:model.live.debounce.600ms="names.{{ $item['key'] }}">
                        @if ($item['label'] !== $item['original'])
                            <small>{{ __('was :name', ['name' => $item['original']]) }}</small>
                        @endif
                    </div>
                    <div class="segmented segmented--sm" role="radiogroup" aria-label="{{ __('Where :name lives', ['name' => $item['label']]) }}">
                        @foreach ($places as $place => $placeName)
                            <button type="button" role="radio" aria-checked="{{ $item['where'] === $place ? 'true' : 'false' }}" title="{{ $placeHints[$place] }}" wire:click="place('{{ $item['key'] }}', '{{ $place }}')">{{ $placeName }}</button>
                        @endforeach
                    </div>
                    <span class="menu-edit__order">
                        <button type="button" class="icon-btn" wire:click="move('{{ $item['key'] }}', -1)" @disabled($loop->first) aria-label="{{ __('Move :name up', ['name' => $item['label']]) }}">↑</button>
                        <button type="button" class="icon-btn" wire:click="move('{{ $item['key'] }}', 1)" @disabled($loop->last) aria-label="{{ __('Move :name down', ['name' => $item['label']]) }}">↓</button>
                    </span>
                </div>
            @endforeach
        </div>

        <div class="yours__foot">
            <button type="button" class="btn btn--quiet" wire:click="putBack">{{ __('Put back the usual menu') }}</button>
            <span class="field__hint" style="margin-left: auto">{{ __('Kept as you go. The stickers change when you turn the page.') }}</span>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: how it will look --}}
    @php
        $stickers = array_values(array_filter($items, fn ($i) => $i['where'] === 'sticker'));
        $tabs = array_values(array_filter($items, fn ($i) => $i['where'] === 'thumb'));
        $indexOnly = array_column(array_filter($items, fn ($i) => $i['where'] === 'index'), 'label');
        $hidden = array_column(array_filter($items, fn ($i) => $i['where'] === 'hidden'), 'label');
    @endphp
    <section class="page page--narrow-right page--stack" style="--ribbon: var(--lilac)">
        <div class="ribbon" aria-hidden="true"></div>
        <p class="eyebrow ribbon-clear">{{ __('On a computer') }}</p>
        @if (\App\Support\YourBook::desk())
            {{-- The Dashboard layout: the sidebar, as it will read --}}
            <div class="menu-preview menu-preview--desk" role="img" aria-label="{{ __('Content: :list.', ['list' => implode(', ', array_column($stickers, 'label')) ?: __('none')]) }} {{ __('Tools: :list.', ['list' => implode(', ', array_column($tabs, 'label')) ?: __('none')]) }}">
                <span class="menu-preview__side">
                    <b>{{ __('Content') }}</b>
                    @foreach ($stickers as $item)<i style="--tone: var(--{{ $item['tone'] }})">{{ $item['label'] }}</i>@endforeach
                    <b>{{ __('Tools') }}</b>
                    @foreach ($tabs as $item)<i style="--tone: var(--{{ $item['tone'] }})">{{ $item['label'] }}</i>@endforeach
                </span>
                <span class="menu-preview__panes"><span></span><span></span><span></span></span>
            </div>
        @else
            <div class="menu-preview" role="img" aria-label="{{ __('Stickers: :list.', ['list' => implode(', ', array_column($stickers, 'label')) ?: __('none')]) }} {{ __('Side tabs: :list.', ['list' => implode(', ', array_column($tabs, 'label')) ?: __('none')]) }}">
                <span class="menu-preview__flags">
                    <i class="menu-preview__stacy">{{ __('Ask Stacy') }}</i>
                    @foreach ($stickers as $item)<i style="background: var(--{{ $item['tone'] }})">{{ $item['label'] }}</i>@endforeach
                </span>
                <span class="menu-preview__book"><span></span><span></span></span>
                <span class="menu-preview__tabs">
                    @foreach ($tabs as $item)<i style="background: var(--{{ $item['tone'] }})"><span>{{ $item['label'] }}</span></i>@endforeach
                </span>
            </div>
        @endif
        <p class="field__hint">
            @if ($indexOnly) {{ __('Only in the Index: :list.', ['list' => implode(', ', $indexOnly)]) }} @endif
            @if ($hidden) {{ __('Hidden: :list. Search still finds them.', ['list' => implode(', ', $hidden)]) }} @else {{ __('Nothing hidden.') }} @endif
        </p>

        <p class="eyebrow" style="margin-top: 10px">{{ __('On a phone') }}</p>
        <div class="two-up">
            @foreach ([0 => __('First in the bar'), 1 => __('Second in the bar')] as $slot => $slotName)
                <div class="field">
                    <label for="dock-{{ $slot }}">{{ $slotName }}</label>
                    <select id="dock-{{ $slot }}" wire:change="dockPick({{ $slot }}, $event.target.value)">
                        @foreach ($dockChoices as $choice)
                            <option value="{{ $choice['key'] }}" @selected(($dock[$slot]['key'] ?? null) === $choice['key'])>{{ $choice['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
        <div class="menu-preview__dock" role="img" aria-label="{{ __('The phone bar: :list.', ['list' => implode(', ', array_merge(array_column($dock, 'label'), [__('Ask Stacy'), __('Waiting'), __('Index')]))]) }}">
            @foreach ($dock as $n => $item)
                <span @class(['is-on' => $n === 0])><i style="--tone: var(--{{ $item['tone'] }})"><x-admin.icon :name="$item['icon']" size="16" /></i><b class="menu-preview__word">{{ $item['label'] }}</b></span>
            @endforeach
            <span class="menu-preview__star"><i><x-admin.icon name="stacy" size="18" /></i>{{ __('Ask Stacy') }}</span>
            <span><i><x-admin.icon name="comment" size="16" /></i>{{ __('Waiting') }}</span>
            <span><i><x-admin.icon name="list" size="16" /></i>{{ __('Index') }}</span>
        </div>
        <p class="margin-note" style="margin-left: 0">{{ __('Ask Stacy, Waiting and the Index always stay, so nothing can get lost.') }}</p>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
