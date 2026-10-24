{{-- The blog page, arranged as sections (the blog hook, Booklet 1.3). --}}
<x-site.layout :title="request()->is('/') ? null : 'Blog'" :description="$site->tagline()" :robots="$draft ? 'noindex, nofollow' : null">
    @php \App\Support\StructuredData::listing($category !== '' ? ($categories->first(fn ($c) => $c->slug() === $category)?->title() ?? 'Blog') : 'Blog', $featured ? collect([$featured])->merge($posts) : $posts); @endphp
    <div class="pb-page pb-blog" data-pb-page="blog">
        @unless (collect($sections)->contains(fn ($s) => in_array($s['type'], ['hero', 'heading'], true)))
            <h1 class="sr-only">@if (request()->is('/')){{ $site->name() }}: @endif The blog</h1>
        @endunless
        @include('insert-page-builder::sections', ['live' => false, 'embedded' => false])
    </div>
    @if ($editable)
        @include('insert-page-builder::editor', ['page' => null, 'start' => request()->boolean('edit'),
            'query' => http_build_query(array_filter(['category' => $category ?: null, 'page' => $paginator->currentPage() > 1 ? $paginator->currentPage() : null]))])
    @endif
</x-site.layout>
