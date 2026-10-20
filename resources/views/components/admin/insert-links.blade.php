@props(['chapter'])
@php
    $tucked = rescue(fn () => app(\App\Inserts\InsertManager::class)->active()
        ->filter(fn ($i) => $i->page() && mb_strtolower($i->page()['chapter']) === mb_strtolower($chapter) && $i->canOpen(request()->user())), collect(), false);
@endphp
@if ($tucked->isNotEmpty())
<div {{ $attributes->merge(['class' => 'chapter-links']) }}>
@foreach ($tucked as $i)
    <a href="{{ $i->pageUrl() }}" class="back" wire:navigate><span class="insert-chip insert-chip--small" style="--tone: var(--{{ $i->sticker() }})" aria-hidden="true"></span> {{ $i->page()['title'] }} →</a>
@endforeach
</div>
@endif
