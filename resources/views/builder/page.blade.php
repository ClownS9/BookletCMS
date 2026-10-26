{{-- A page built of sections, in the site's own layout (whichever theme it wears). --}}
<x-site.layout :title="$isHome && request()->is('/') ? null : ($entry->get('meta_title') ?: $entry->title())" :description="$site->description($entry)"
    :image-alt="$site->imageAlt($entry) ?: null" :image="$site->shareImage($entry) ?? \App\Support\ShareImage::urlFor($entry)" :robots="$draft ? 'noindex, nofollow' : null">
    @php \App\Support\StructuredData::page($entry); @endphp
    <div class="pb-page" data-pb-page="{{ $entry->id() }}">
        @unless (collect($sections)->contains(fn ($s) => in_array($s['type'], ['hero', 'heading', 'banner'], true)))
            <h1 class="sr-only">{{ $entry->title() }}</h1>
        @endunless
        @include('builder.sections', ['live' => false, 'embedded' => false])
    </div>
    @if ($editable)
        {{-- Only for people who can build it: the button, and the editor (readers never get either). --}}
        @include('builder.editor', ['target' => $entry->id(), 'start' => request()->boolean('edit') || $build->published === null,
            'draft' => ! $entry->published(), 'back' => $back])
    @endif
</x-site.layout>
