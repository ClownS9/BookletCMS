{{--
    The editor, for people who can edit this page (readers never get it):
    "Edit this page" in the corner, and everything after it. Expects $insert,
    $page (a built page, or null for the blog page) and $start (open the
    editor straight away).
--}}
@php $id = $page ? $page->id : 'blog'; @endphp
<div id="pb-editor" hidden
     data-edit="{{ route('site.insert.page-builder.edit', $id) }}"
     data-act="{{ route('site.insert.page-builder.act', $id) }}"
     data-preview="{{ route('site.insert.page-builder.preview', $id) }}"
     data-query="{{ $query ?? '' }}"
     data-token="{{ csrf_token() }}"
     data-css="{{ $insert->asset('edit.css') }}"
     data-label="{{ $page ? 'Edit this page' : 'Arrange this page' }}"
     data-draft="{{ $page && $page->field('published') === null ? 1 : 0 }}"
     data-start="{{ $start ? 1 : 0 }}"
     data-pages="{{ route('admin.insert', $insert->key) }}"></div>
<script src="{{ $insert->asset('edit.js') }}" defer></script>
