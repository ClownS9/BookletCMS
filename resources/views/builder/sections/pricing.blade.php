@include('builder.sections.head')
<ul class="pb-grid pb-grid--{{ min(4, max(1, count($f->items('items')))) }} pb-plans" role="list">
    @foreach ($f->items('items') as $i => $plan)
        <li @class(['pb-plan', 'pb-plan--featured' => $plan->get('featured')]){{ $f->itemAttrs('items', $i) }}>
            {{ $plan->text('name', 'h3', 'pb-plan__name') }}
            @if ($plan->has('price', 'per'))
                <p class="pb-plan__price">{{ $plan->text('price', 'span', 'pb-plan__amount') }} {{ $plan->text('per', 'span', 'pb-plan__per') }}</p>
            @endif
            @if ($plan->live)
                {{ $plan->words('words', 'pb-plan__list pb-plan__list--edit') }}
            @elseif ($plan->lines('words') !== [])
                <ul class="pb-plan__list">
                    @foreach ($plan->lines('words') as $line) <li>{{ $plan->inline($line) }}</li> @endforeach
                </ul>
            @endif
            @if ($plan->has('button.label'))
                <p class="pb-plan__button">{{ $plan->link('button', $plan->get('featured') ? 'button button--ink' : 'button') }}</p>
            @endif
        </li>
    @endforeach
</ul>
