{{-- The thumb index: the tools, as tabs cut into the edge of the pages, like a
     dictionary's. Each person chooses what goes here (Your menu). Wide
     screens only; phones find them in the Index. --}}
@php $tabs = \App\Support\AdminNav::menuIn('thumb'); @endphp
@if ($tabs !== [])
    <nav class="thumbs" aria-label="{{ __('Thumb index') }}">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['href'] }}" class="thumbs__tab" style="--tone: var(--{{ $tab['tone'] }})" wire:navigate
               @if ($tab['active']) aria-current="page" @endif
               @if ($tab['badge']) aria-label="{{ $tab['label'] }}, {{ trans_choice(':count new|:count new', $tab['badge']) }}" @endif>
                <span class="thumbs__label">{{ $tab['label'] }}</span>
                @if ($tab['badge'])
                    <span class="thumbs__badge" aria-hidden="true">{{ $tab['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </nav>
@endif
