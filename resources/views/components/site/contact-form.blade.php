{{--
    The contact form (messages arrive in the Inbox). Left out of the plain-file copy.
    <x-site.contact-form :entry="$entry" />
--}}
@props(['entry'])
@unless (request()->attributes->get('booklet_static'))
    <livewire:site.contact-form :page="$entry->uri() ?? ''" />
@endunless
