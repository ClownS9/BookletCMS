{{-- One of your own forms (Comments › Forms), with a few words beside it. --}}
@php $made = \App\Support\Forms::find((string) $f->get('form')); @endphp
<div class="pb-contact pb-form">
    @if ($f->has('title', 'words'))
        <div class="pb-contact__words">
            {{ $f->text('title', 'h2', 'pb-title') }}
            {{ $f->words('words', 'pb-lede') }}
        </div>
    @endif
    <div class="pb-contact__form">
        @if (! $made)
            @if ($f->live)
                <p class="pb-nothing">{{ __('Choose which form goes here, in this section’s settings. Make one in the book, under Comments › Forms.') }}</p>
            @endif
        @elseif ($f->live)
            {{-- While editing: how it looks (the real form works for readers). --}}
            <div class="pb-contact__sample" aria-hidden="true">
                @foreach (array_slice(\App\Support\Forms::fields($made), 0, 6) as $q)
                    <span>{{ $q['label'] }}</span><i @class(['pb-contact__tall' => $q['type'] === 'long'])></i>
                @endforeach
                <b>{{ $made->get('button') ?: 'Send' }}</b>
            </div>
            @unless (\App\Support\Forms::open($made)) <p class="pb-nothing">{{ __('It isn’t taking answers now, so readers see that it’s closed.') }}</p> @endunless
        @elseif (request()->attributes->get('booklet_static'))
            <p class="pb-nothing">{{ __('The form works on the live site.') }}</p>
        @else
            <livewire:site.made-form :handle="$made->handle()" :page="request()->path()" :key="'pb-form-'.$s['id']" />
        @endif
    </div>
</div>
