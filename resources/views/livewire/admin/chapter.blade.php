<div style="display: contents">
    <section class="page page--left">
        <a href="{{ route('admin.contents') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Contents') }}</a>
        <p class="eyebrow" style="margin-top: 18px">{{ __('Chapter in progress') }}</p>
        <h1 class="display" style="margin-top: 8px">{{ $name }}</h1>
    </section>
    <section class="page page--right" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>
        <p class="empty-note" style="max-width: 460px">
            @if ($chapter === 'stacy')
                Stacy is packing her bags. She moves in once an AI service is chosen for her to think with.
            @else
                This chapter is still being written.
            @endif
        </p>
    </section>
</div>
