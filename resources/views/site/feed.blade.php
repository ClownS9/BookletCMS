{!! '<' . '?xml version="1.0" encoding="UTF-8"?' . '>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
    <channel>
        <title>{{ $site->name() }}</title>
        <link>{{ $site->absolute(url('/')) }}</link>
        <atom:link href="{{ $site->absolute(route('site.feed')) }}" rel="self" type="application/rss+xml" />
        <description>{{ $site->tagline() ?? $site->name() }}</description>
        <language>{{ str_replace('_', '-', app()->getLocale()) }}</language>
        <lastBuildDate>{{ \Illuminate\Support\Carbon::parse($updated)->toRssString() }}</lastBuildDate>
        @foreach ($posts as $post)
            @php $author = $site->author($post); @endphp
            <item>
                <title>{{ $post->title() }}</title>
                <link>{{ $site->absolute($site->url($post)) }}</link>
                <guid isPermaLink="false">{{ $post->id() }}</guid>
                @if ($post->date())<pubDate>{{ $post->date()->toRssString() }}</pubDate>@endif
                @if ($author)<dc:creator xmlns:dc="http://purl.org/dc/elements/1.1/">{{ $author->byline ?: $author->name }}</dc:creator>@endif
                @foreach ($site->terms($post, 'categories') as $category)
                    <category>{{ $category->title() }}</category>
                @endforeach
                <description>{{ $site->excerpt($post, 300) }}</description>
                @if (\App\Support\Members::locked($post))
                    {{-- Members-only: the first lines and a link, never the rest. --}}
                    <content:encoded><![CDATA[{!! str_replace(']]>', ']]]]><![CDATA[>', '<p>'.e($site->excerpt($post, 300)).'</p><p><a href="'.e($site->absolute($site->url($post))).'">'.e(__('For members: read it on the site')).'</a></p>') !!}]]></content:encoded>
                @else
                    <content:encoded><![CDATA[{!! str_replace(']]>', ']]]]><![CDATA[>', (string) $site->markdown($post->get('content')).$site->blocks($post)->toHtml()) !!}]]></content:encoded>
                @endif
            </item>
        @endforeach
    </channel>
</rss>
