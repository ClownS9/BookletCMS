@include('builder.sections.head')
<div class="pb-faq">
    @foreach ($f->items('items') as $i => $q)
        <details class="pb-faq__item"{{ $f->itemAttrs('items', $i) }} @if ($f->live) open @endif>
            <summary>{{ $q->text('question', 'span', 'pb-faq__question') }}</summary>
            {{ $q->words('answer', 'pb-faq__answer') }}
        </details>
    @endforeach
</div>
@unless ($f->live)
    @php
        // Questions and answers, for search engines.
        $qa = collect($f->items('items'))->filter(fn ($q) => $q->filled('question') && $q->filled('answer'));
        if ($qa->isNotEmpty() && empty($embedded)) {
            \App\Support\StructuredData::add(['@type' => 'FAQPage', 'mainEntity' => $qa->map(fn ($q) => [
                '@type' => 'Question', 'name' => $q->get('question'),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => \App\PageBuilder\Fields::plain((string) $q->get('answer'))],
            ])->values()->all()]);
        }
    @endphp
@endunless
