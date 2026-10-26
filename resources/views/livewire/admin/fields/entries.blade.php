{{-- Linked entries, as book spines. Expects $target ("related" or "blocks.2.entries"), $ids, $label, $max (0 = no limit), $labelId. --}}
@php
    $chosen = \App\Support\Links::entries($ids, false);
    $missing = count($ids) - $chosen->count();
    $full = $max > 0 && count($ids) >= $max;
@endphp
<div class="spines" x-data="{ from: null, over: null }">
    @if ($chosen->isNotEmpty())
        <ol class="spines__list" role="list" aria-labelledby="{{ $labelId }}">
            @foreach ($chosen as $i => $linked)
                <li class="spine" wire:key="spine-{{ $target }}-{{ $linked->id() }}" draggable="true"
                    style="--spine: {{ $linked->collection()?->get('sticker', 'var(--sand)') ?? 'var(--sand)' }}"
                    :class="{ 'spine--over': over === {{ $i }} && from !== {{ $i }}, 'spine--dragging': from === {{ $i }} }"
                    x-on:dragstart="from = {{ $i }}; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', @js($linked->title()))"
                    x-on:dragend="from = over = null" x-on:dragover.prevent="over = {{ $i }}"
                    x-on:drop.prevent="from !== null && from !== {{ $i }} && $wire.moveLink(@js($target), from, {{ $i }}); from = over = null">
                    <span class="spine__band" aria-hidden="true"></span>
                    <span class="spine__text">
                        <a class="spine__title" href="{{ route('admin.entries.edit', [$linked->collectionHandle(), $linked->id()]) }}" wire:navigate>{{ $linked->title() ?: 'Untitled' }}</a>
                        <span class="spine__meta">{{ $linked->collection()?->title() }}@if ($linked->date()) · {{ $linked->date()->format('j M Y') }}@endif</span>
                    </span>
                    @if ($linked->status() !== 'published')
                        <span class="sticker sticker--sm sticker--{{ $linked->status() }}" style="--tilt: -2deg" title="{{ __('Readers only see it once it’s published') }}">{{ ucfirst($linked->status()) }}</span>
                    @endif
                    <span class="spine__tools">
                        <button type="button" class="icon-btn icon-btn--sm" wire:click="moveLink(@js($target), {{ $i }}, {{ $i - 1 }})" @disabled($loop->first) aria-label="Move {{ $linked->title() }} up">↑</button>
                        <button type="button" class="icon-btn icon-btn--sm" wire:click="moveLink(@js($target), {{ $i }}, {{ $i + 1 }})" @disabled($loop->last) aria-label="Move {{ $linked->title() }} down">↓</button>
                        <button type="button" class="icon-btn icon-btn--sm icon-btn--danger" wire:click="unlinkEntry(@js($target), @js($linked->id()))" aria-label="Unlink {{ $linked->title() }}">×</button>
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
    @if ($missing > 0)
        <p class="field__hint">{{ trans_choice(':count linked entry has been torn out; it’s dropped when you save.|:count linked entries have been torn out; they’re dropped when you save.', $missing) }}</p>
    @endif
    @unless ($full)
        <button type="button" class="btn btn--dashed btn--sm spines__add" wire:click="openShelf(@js($target))">+ {{ $label }}</button>
    @else
        <p class="field__hint">{{ __('That’s all :max it holds. Unlink one to choose another.', ['max' => $max]) }}</p>
    @endunless
</div>
