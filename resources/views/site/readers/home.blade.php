{{-- A reader's own page. A theme can replace this page (site/readers/home.blade.php); what's on it stays Booklet's. --}}
<x-site.layout :title="__('Your account')" robots="noindex">
    <div class="wrap wrap--narrow section reader-desk">
        <livewire:site.reader-page />
    </div>
</x-site.layout>
