{{-- What switched-on inserts have to say today --}}
@foreach ($insertCards as $card)
    <section class="insert-card" aria-labelledby="card-{{ $card['key'] }}" wire:key="card-{{ $card['key'] }}" style="--tone: var(--{{ $card['insert']->sticker() }})">
        <h2 class="eyebrow section-label section-label--gap" id="card-{{ $card['key'] }}">
            <span class="insert-chip insert-chip--small" aria-hidden="true"></span>
            {{ $card['title'].($card['count'] !== null ? ' · '.number_format($card['count']) : '') }}
        </h2>
        @if ($card['text']) <p class="insert-card__text">{{ __($card['text']) }}</p> @endif
        @if ($card['items'] !== [])
            <ul class="review-list">
                @foreach ($card['items'] as $item)
                    <li>
                        @if ($item['href'])
                            <a href="{{ $item['href'] }}" @if (str_starts_with($item['href'], '/') || str_starts_with($item['href'], url('/'))) wire:navigate @endif>
                        @else
                            <span class="review-list__row">
                        @endif
                            <span class="dot" style="--dot: var(--tone)" aria-hidden="true"></span>
                            <span style="min-width: 0">
                                <span class="review-list__title">{{ __($item['label']) }}</span>
                                @if ($item['meta'] !== '') <span class="review-list__meta">{{ $item['meta'] }}</span> @endif
                            </span>
                        @if ($item['href']) </a> @else </span> @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($card['link'])
            <a href="{{ $card['link']['href'] }}" class="review-list__more" @if (str_starts_with($card['link']['href'], '/') || str_starts_with($card['link']['href'], url('/'))) wire:navigate @endif>{{ $card['link']['label'] }} →</a>
        @endif
    </section>
@endforeach
