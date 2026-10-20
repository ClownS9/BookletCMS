{!! '<' . '?xml version="1.0" encoding="UTF-8"?' . '>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
    @foreach ($urls as $url)
        <url>
            <loc>{{ $url['loc'] }}</loc>
            @if ($url['lastmod'])<lastmod>{{ \Illuminate\Support\Carbon::parse($url['lastmod'])->toAtomString() }}</lastmod>@endif
            @foreach ($url['alternates'] ?? [] as $alternate)
                <xhtml:link rel="alternate" hreflang="{{ $alternate['code'] }}" href="{{ $alternate['url'] }}"/>
                @if ($alternate['original'])<xhtml:link rel="alternate" hreflang="x-default" href="{{ $alternate['url'] }}"/>@endif
            @endforeach
        </url>
    @endforeach
</urlset>
