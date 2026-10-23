{{--
    Readers' comments on an entry, and the form to leave one: shown when comments are
    open (Settings), never on proof copies or the plain-file copy. <x-site.comments :entry="$entry" />
--}}
@props(['entry'])
@if (app(\App\Booklet\Site::class)->commentsOpen() && ! request()->attributes->has('booklet_proof') && ! request()->attributes->get('booklet_static'))
    <livewire:site.comments :entry-id="$entry->id()" />
@endif
