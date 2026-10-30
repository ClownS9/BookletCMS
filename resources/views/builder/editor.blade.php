{{--
    The editor, for people who can build this page (readers never get it):
    "Edit this page" in the corner (or "Build this page", on one that isn't
    built yet), and everything after it. Expects $target (an entry's id, or
    "blog"), $built, $start (open the editor straight away) and $draft (not
    on the site yet).
--}}
@php
    $built ??= true;
    // The editor speaks the book's language (the page around it, the site's).
    $bookLang = \App\Support\BookLanguage::for(request());
@endphp
<div id="pb-editor" hidden
     data-edit="{{ route('builder.edit', $target) }}"
     data-act="{{ route('builder.act', $target) }}"
     data-preview="{{ route('builder.preview', $target) }}"
     data-here="{{ route('builder.here', $target) }}"
     data-help="{{ route('admin.help.building') }}"
     data-begin="{{ $built ? '' : route('builder.start', $target) }}"
     data-query="{{ $query ?? '' }}"
     data-token="{{ csrf_token() }}"
     data-css="{{ asset('booklet/builder-edit.css') }}?v={{ filemtime(public_path('booklet/builder-edit.css')) }}"
     data-label="{{ __(! $built ? 'Build this page' : ($target === 'blog' ? 'Arrange this page' : 'Edit this page'), [], $bookLang) }}"
     data-words="{{ json_encode(\App\Support\ScriptWords::for('builder-edit.js', $bookLang), JSON_UNESCAPED_UNICODE) }}"
     data-title="{{ $title ?? '' }}"
     data-draft="{{ ($draft ?? false) ? 1 : 0 }}"
     data-start="{{ ($start ?? false) ? 1 : 0 }}"
     data-pages="{{ $back ?? url('/') }}"></div>
<script src="{{ asset('booklet/builder-edit.js') }}?v={{ filemtime(public_path('booklet/builder-edit.js')) }}" defer></script>
