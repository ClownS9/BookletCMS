{{-- A built page: its sections, stacked, in the site's own layout (whichever theme it wears). --}}
<x-site.layout :title="$home ? null : $title" :description="$description" :robots="$draft ? 'noindex, nofollow' : null">
    <div class="pb-page" data-pb-page="{{ $page->id }}">
        @include('insert-page-builder::sections', ['live' => false, 'embedded' => false])
    </div>
    @if ($editable)
        {{-- Only for people who can edit it: the button, and the editor (readers never get either). --}}
        <div id="pb-editor" hidden
             data-edit="{{ route('site.insert.page-builder.edit', $page->id) }}"
             data-act="{{ route('site.insert.page-builder.act', $page->id) }}"
             data-preview="{{ route('site.insert.page-builder.preview', $page->id) }}"
             data-token="{{ csrf_token() }}"
             data-css="{{ $insert->asset('edit.css') }}"
             data-draft="{{ $draft ? 1 : 0 }}"
             data-start="{{ $draft || request()->boolean('edit') ? 1 : 0 }}"
             data-pages="{{ route('admin.insert', $insert->key) }}"></div>
        <script src="{{ $insert->asset('edit.js') }}" defer></script>
    @endif
</x-site.layout>
