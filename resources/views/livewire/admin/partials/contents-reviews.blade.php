@foreach ([['Waiting for your review', $forReview, 'sky'], ['Sent back to you', $sentBack, 'rose']] as [$label, $list, $tone])
    @if ($list->isNotEmpty())
        <h2 class="eyebrow section-label section-label--gap" @if ($tone === 'sky') id="for-review" @endif>{{ __($label) }} · {{ $list->count() }}</h2>
        <ul class="review-list">
            @foreach ($list->take(5) as $e)
                @php $info = \App\Support\Reviews::details($e); @endphp
                <li wire:key="rv-{{ $e->id() }}">
                    <a href="{{ route('admin.entries.edit', [$e->collectionHandle(), $e->id()]) }}" wire:navigate>
                        <span class="dot" style="--dot: var(--{{ $tone }})" aria-hidden="true"></span>
                        <span style="min-width: 0">
                            <span class="review-list__title">{{ $e->title() ?: __('Untitled') }}</span>
                            <span class="review-list__meta">{{ $info['by']?->name ?? __('Someone') }}{{ $info['at'] ? ' · '.$info['at']->diffForHumans() : '' }}@if ($info['note'] !== '') · “{{ \Illuminate\Support\Str::limit($info['note'], 70) }}”@endif</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        @if ($list->count() > 5)
            <a href="{{ route('admin.entries', ['collection' => 'posts', 'status' => 'review']) }}" class="review-list__more" wire:navigate>{{ __('All :count →', ['count' => $list->count()]) }}</a>
        @endif
    @endif
@endforeach
