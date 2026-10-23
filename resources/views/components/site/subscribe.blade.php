{{--
    New posts by email: the sign-up box. Left out of the plain-file copy (it needs a
    server to send to). <x-site.subscribe place="post" />
--}}
@props(['place' => 'post'])
@unless (request()->attributes->get('booklet_static'))
    <livewire:site.subscribe :place="$place" />
@endunless
