@props(['item', 'id'])
{{-- A wide panel under a top menu item: its links in columns, then (if chosen) the newest posts, a picture and a few words. --}}
@php
    // Children with their own children become columns; single links share a first column.
    $loose = collect($item['children'])->filter(fn ($c) => ! $c['children'])->values();
    $groups = collect($item['children'])->filter(fn ($c) => $c['children'])->values();
    $mega = $item['mega'];
@endphp
<div class="menu__sub menu__mega" id="{{ $id }}">
    <div class="menu__mega-inner">
        <div class="menu__mega-links">
            @if ($loose->isNotEmpty())
                <ul class="menu__mega-col">
                    @foreach ($loose as $child)
                        <li><a href="{{ $child['url'] }}" class="menu__sublink menu__sublink--big" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a></li>
                    @endforeach
                </ul>
            @endif
            @foreach ($groups as $group)
                <div class="menu__mega-col">
                    <a href="{{ $group['url'] }}" class="menu__mega-heading" @if ($group['active']) aria-current="page" @endif>{{ $group['label'] }}</a>
                    <ul>
                        @foreach ($group['children'] as $grand)
                            <li><a href="{{ $grand['url'] }}" class="menu__sublink" @if ($grand['active']) aria-current="page" @endif>{{ $grand['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            @if ($mega['latest'])
                <div class="menu__mega-col menu__mega-latest">
                    <span class="menu__mega-heading">Newest</span>
                    <ul>
                        @foreach ($mega['latest'] as $post)
                            <li><a href="{{ $post['url'] }}" class="menu__sublink">{{ $post['label'] }} @if ($post['date']) <small>{{ $post['date'] }}</small> @endif</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        @if ($mega['picture'] || $mega['words'])
            <a href="{{ $item['url'] }}" class="menu__mega-card">
                @if ($mega['picture'])
                    <img src="{{ $mega['picture']['url'] }}" @if ($mega['picture']['srcset']) srcset="{{ $mega['picture']['srcset'] }}" sizes="320px" @endif
                         alt="{{ $mega['picture']['alt'] }}" style="object-position: {{ $mega['picture']['focus'] }}" loading="lazy">
                @endif
                <span class="menu__mega-title">{{ $item['label'] }} <span aria-hidden="true">→</span></span>
                @if ($mega['words']) <span class="menu__mega-words">{{ $mega['words'] }}</span> @endif
            </a>
        @endif
    </div>
</div>
