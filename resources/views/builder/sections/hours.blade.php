@php $where = $f->has('address', 'button.label'); @endphp
<div @class(['pb-hours', 'pb-hours--alone' => ! $where])>
    <div class="pb-hours__times">
        {{ $f->text('title', 'h2', 'pb-title') }}
        <dl class="pb-hours__list">
            @foreach ($f->items('items') as $i => $row)
                <div class="pb-hours__row"{{ $f->itemAttrs('items', $i) }}>
                    <dt>{{ $row->text('days', 'span') }}</dt>
                    <dd>{{ $row->text('time', 'span') }}</dd>
                </div>
            @endforeach
        </dl>
        {{ $f->words('note', 'pb-hours__note') }}
    </div>
    @if ($where)
        <div class="pb-hours__where">
            {{ $f->words('address', 'pb-hours__address') }}
            @if ($f->has('button.label'))
                <p class="pb-buttons">{{ $f->link('button', 'button button--ink') }}</p>
            @endif
        </div>
    @endif
</div>
