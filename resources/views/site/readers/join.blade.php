{{-- Making an account on the site. A theme can replace this page (site/readers/join.blade.php); the form stays Booklet's. --}}
<x-site.layout :title="__('Join')" robots="noindex">
    <div class="wrap wrap--narrow section reader-desk">
        <livewire:site.reader-join :invite="$invite ?? null" />
    </div>
</x-site.layout>
