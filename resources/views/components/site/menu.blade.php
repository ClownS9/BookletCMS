{{--
    A menu from Site menus, with its drop-downs and wide panels (and the seasonal
    version when one is on): <x-site.menu />, or <x-site.menu handle="side" label="More" />.
    The menu button in <x-site.tools /> opens it on phones (it looks for id="site-menu").
--}}
@props(['handle' => 'header', 'items' => null, 'id' => 'site-menu', 'label' => 'Site'])
@php $menu = $items ?? app(\App\Booklet\Site::class)->menu($handle); @endphp
@if ($menu)
    <nav class="menu" id="{{ $id }}" aria-label="{{ $label }}">
        <ul class="menu__list">
            @foreach ($menu as $n => $item)
                @php $opens = $item['children'] || $item['mega']; @endphp
                <li @class(['menu__item', 'menu__item--parent' => $opens, 'menu__item--mega' => $item['mega'], 'menu__item--within' => $item['within'] && ! $item['active']])>
                    <a href="{{ $item['url'] }}" class="menu__link" @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @if ($opens)
                        <button type="button" class="menu__caret" data-menu-sub aria-expanded="false" aria-controls="menu-sub-{{ $n }}">
                            <span class="sr-only">More under {{ $item['label'] }}</span>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                        </button>
                        @if ($item['mega'])
                            <x-site.mega-panel :item="$item" :id="'menu-sub-'.$n" />
                        @else
                        <ul class="menu__sub" id="menu-sub-{{ $n }}">
                            @foreach ($item['children'] as $child)
                                <li>
                                    <a href="{{ $child['url'] }}" class="menu__sublink" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
                                    @if ($child['children'])
                                        <ul class="menu__subsub">
                                            @foreach ($child['children'] as $grand)
                                                <li><a href="{{ $grand['url'] }}" class="menu__sublink" @if ($grand['active']) aria-current="page" @endif>{{ $grand['label'] }}</a></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
@endif
