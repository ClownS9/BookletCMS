{{-- The month in ink: visits each day for 30 days, against the 30 before --}}
<svg viewBox="0 0 400 170" @isset($chartClass) class="{{ $chartClass }}" @endisset role="img" aria-label="{{ __('Visits each day for the last 30 days: :total in all.', ['total' => number_format($month['total'])]) }}@if ($month['peak']) {{ __('Busiest on :date, with :views.', ['date' => $month['peak']['date'], 'views' => number_format($month['peak']['views'])]) }}@endif">
    @foreach ($month['grid'] as $line)
        <line x1="30" x2="396" y1="{{ $line['y'] }}" y2="{{ $line['y'] }}" class="ink-chart__rule"/>
        <text x="24" y="{{ $line['y'] + 3 }}" text-anchor="end" class="ink-chart__tick">{{ $line['label'] }}</text>
    @endforeach
    <path d="{{ $month['area'] }}" class="ink-chart__wash"/>
    <path d="{{ $month['prev'] }}" class="ink-chart__before"/>
    <path d="{{ $month['line'] }}" class="ink-chart__line" pathLength="1"/>
    @if ($month['peak'])
        <circle cx="{{ $month['peak']['x'] }}" cy="{{ $month['peak']['y'] }}" r="4.5" class="ink-chart__peak"/>
    @endif
    <text x="30" y="166" class="ink-chart__tick">{{ $month['first'] }}</text>
    <text x="213" y="166" text-anchor="middle" class="ink-chart__tick">{{ $month['middle'] }}</text>
    <text x="396" y="166" text-anchor="end" class="ink-chart__tick">{{ __('Today') }}</text>
</svg>
