<div class="pb-contact">
    <div class="pb-contact__words">
        {{ $f->text('title', 'h2', 'pb-title') }}
        {{ $f->words('words', 'pb-lede') }}
        @if ($f->live)
            {{ $f->words('details', 'pb-contact__details') }}
        @elseif ($f->lines('details') !== [])
            <ul class="pb-contact__details">
                @foreach ($f->lines('details') as $line) <li>{{ $f->inline($line) }}</li> @endforeach
            </ul>
        @endif
    </div>
    <div class="pb-contact__form">
        @if ($f->live)
            {{-- While editing: how it looks (the real form works for readers). --}}
            <div class="pb-contact__sample" aria-hidden="true">
                <span>Name</span><i></i><span>Email</span><i></i><span>Message</span><i class="pb-contact__tall"></i><b>Send</b>
            </div>
        @elseif (request()->attributes->get('booklet_static'))
            <p class="pb-nothing">The form works on the live site.</p>
        @else
            <livewire:site.contact-form :page="request()->path()" :key="'pb-contact-'.$s['id']" />
        @endif
    </div>
</div>
