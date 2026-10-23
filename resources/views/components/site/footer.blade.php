{{--
    The colophon: the year and name, the footer menu (in columns when it has groups),
    and the feed. <x-site.footer /> or <x-site.footer handle="footer-shop" />.
--}}
@props(['handle' => 'footer'])
@php $site = app(\App\Booklet\Site::class); $footer = $site->menu($handle); @endphp
<footer class="colophon" id="colophon">
    <div class="wrap colophon__inner">
        <span>© {{ now()->year }} {{ $site->name() }}</span>
        @if ($footer)
            @if (collect($footer)->contains(fn ($item) => $item['children']))
                {{-- With groups: a column per top item, its links underneath --}}
                <nav aria-label="Footer" class="colophon__groups">
                    @foreach ($footer as $item)
                        <div class="colophon__group">
                            <a href="{{ $item['url'] }}" class="colophon__heading">{{ $item['label'] }}</a>
                            @if ($item['children'])
                                <ul>
                                    @foreach ($item['children'] as $child)
                                        <li><a href="{{ $child['url'] }}">{{ $child['label'] }}</a></li>
                                        @foreach ($child['children'] as $grand)
                                            <li class="colophon__deeper"><a href="{{ $grand['url'] }}">{{ $grand['label'] }}</a></li>
                                        @endforeach
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </nav>
            @else
                <nav aria-label="Footer" class="colophon__menu">
                    @foreach ($footer as $item)
                        <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            @endif
        @endif
        <span class="colophon__made"><a href="{{ route('site.feed') }}">RSS</a> · made with booklet</span>
    </div>
</footer>
