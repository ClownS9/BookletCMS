{{-- One result in the search window --}}
@php $external = ! empty($item['external']); @endphp
<a href="{{ $item['href'] }}" class="hit" data-hit
   @if ($external) target="_blank" rel="noopener" @else wire:navigate @endif
   x-on:click="remember(@js(['label' => $item['label'], 'meta' => $item['meta'] ?? '', 'href' => $item['href'], 'tone' => $item['tone'] ?? 'var(--sand)']))">
    @if (! empty($item['thumb']))
        <img class="hit__thumb" src="{{ $item['thumb'] }}" alt="" loading="lazy">
    @elseif (! empty($item['initials']))
        <span class="hit__icon hit__icon--person" style="--tone: {{ $item['tone'] }}">{{ $item['initials'] }}</span>
    @else
        <span class="hit__icon" style="--tone: {{ $item['tone'] ?? 'var(--sand)' }}"><x-admin.icon :name="$item['icon'] ?? 'file'" size="16" /></span>
    @endif

    <span class="hit__text">
        <span class="hit__label serif">{{ \App\Support\SearchIndex::mark($item['label'], $needle) }}</span>
        @if (! empty($item['meta'])) <span class="hit__sub">{{ \App\Support\SearchIndex::mark($item['meta'], $needle) }}</span> @endif
    </span>

    @if (! empty($item['status']))
        <span class="sticker sticker--sm sticker--{{ $item['status'] }}">{{ ['published' => 'Live', 'draft' => 'Draft', 'scheduled' => 'Scheduled'][$item['status']] ?? ucfirst($item['status']) }}</span>
    @endif
    <span class="hit__go" aria-hidden="true">{{ $external ? '↗' : '↵' }}</span>
</a>
