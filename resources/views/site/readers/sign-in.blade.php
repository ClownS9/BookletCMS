{{-- Signing in to the site, for readers. A theme can replace this page (site/readers/sign-in.blade.php); the form stays Booklet's. --}}
<x-site.layout :title="__('Sign in')" robots="noindex">
    <div class="wrap wrap--narrow section reader-desk">
        <livewire:site.reader-sign-in />
    </div>
</x-site.layout>
