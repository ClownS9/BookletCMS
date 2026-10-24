{{-- A built page: its sections, stacked, in the site's own layout (whichever theme it wears). --}}
<x-site.layout :title="$home ? null : $title" :description="$description" :robots="$draft ? 'noindex, nofollow' : null">
    <div class="pb-page" data-pb-page="{{ $page->id }}">
        @include('insert-page-builder::sections', ['live' => false, 'embedded' => false])
    </div>
    @if ($editable)
        {{-- Only for people who can edit it: the button, and the editor (readers never get either). --}}
        @include('insert-page-builder::editor', ['start' => $draft || request()->boolean('edit')])
    @endif
</x-site.layout>
