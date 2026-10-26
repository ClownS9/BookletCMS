{{--
    One field of the chosen section, in the drawer. Expects $spec, $path
    ('title', 'items.2.words'…), $value and $sid (the section).
--}}
@php $j = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); @endphp
@switch ($spec['type'])
    @case ('text')
    @case ('url')
        <label class="d-field">
            <span>{{ $spec['label'] }}</span>
            <input type="text" data-set="{{ $path }}" value="{{ is_scalar($value) ? $value : '' }}" maxlength="{{ $spec['type'] === 'url' ? 500 : ($spec['max'] ?? 200) }}"
                   @if ($spec['type'] === 'url') placeholder="{{ ! empty($spec['video']) ? 'https://www.youtube.com/watch?v=…' : '/about or https://…' }}" autocapitalize="off" spellcheck="false" @endif>
        </label>
        @break

    @case ('words')
        <label class="d-field">
            <span>{{ $spec['label'] }}</span>
            <textarea data-set="{{ $path }}" rows="{{ $path === 'body' ? 6 : 3 }}" maxlength="{{ $spec['max'] ?? 2000 }}">{{ is_scalar($value) ? $value : '' }}</textarea>
        </label>
        @break

    @case ('link')
        <div class="d-field" role="group" aria-label="{{ $spec['label'] }}">
            <span>{{ $spec['label'] }}</span>
            <input type="text" data-set="{{ $path }}.label" aria-label="{{ $spec['label'] }}: its words" placeholder="Its words (empty: no button)" value="{{ $value['label'] ?? '' }}" maxlength="60">
            <input type="text" data-set="{{ $path }}.url" aria-label="{{ $spec['label'] }}: where it goes" placeholder="Goes to: /about, https://…, an email" value="{{ $value['url'] ?? '' }}" maxlength="500" autocapitalize="off" spellcheck="false">
        </div>
        @break

    @case ('select')
        @php $options = is_array($spec['options']) ? $spec['options'] : $categories; @endphp
        <label class="d-field">
            <span>{{ $spec['label'] }}</span>
            <select data-set="{{ $path }}">
                @foreach ($options as $k => $words)
                    <option value="{{ $k }}" @selected((string) $value === (string) $k)>{{ $words }}</option>
                @endforeach
            </select>
        </label>
        @break

    @case ('toggle')
        <label class="d-check"><input type="checkbox" data-set="{{ $path }}" @checked($value)> {{ $spec['label'] }}</label>
        @break

    @case ('picture')
        @php $pic = is_string($value) && $value !== '' ? \App\PageBuilder\Fields::pictureFor($value) : null; @endphp
        <div class="d-field">
            <span>{{ $spec['label'] }}</span>
            <span class="d-picture">
                @if ($pic) <img src="{{ $pic['url'] }}" alt="" loading="lazy"> @endif
                <button type="button" class="d-btn" data-do="pick" data-payload="{{ $j(['id' => $sid, 'field' => $path]) }}">{{ $pic ? 'Change' : 'Choose from Media' }}</button>
                @if ($pic) <button type="button" class="d-link" data-do="set" data-payload="{{ $j(['id' => $sid, 'field' => $path, 'value' => '']) }}">Take it out</button> @endif
            </span>
        </div>
        @break

    @case ('pictures')
        @php $list = array_values(array_filter((array) $value, 'is_string')); @endphp
        <div class="d-field">
            <span>{{ $spec['label'] }} · {{ count($list) }}</span>
            <button type="button" class="d-btn" data-do="pick" data-payload="{{ $j(['id' => $sid, 'field' => $path]) }}">{{ $list === [] ? 'Choose from Media' : 'Add or take out' }}</button>
        </div>
        @break

    @case ('items')
        @php $items = array_values(array_filter((array) $value, 'is_array')); $max = (int) ($spec['max_items'] ?? 20); @endphp
        <div class="d-items" role="group" aria-label="{{ $spec['label'] }}">
            <span class="d-label">{{ $spec['label'] }} · {{ count($items) }}</span>
            @foreach ($items as $n => $item)
                @php
                    $first = collect($spec['fields'])->keys()->first(fn ($k) => is_string($item[$k] ?? null) && trim($item[$k]) !== '' && in_array($spec['fields'][$k]['type'], ['text', 'words'], true));
                    $title = $first ? \Illuminate\Support\Str::limit($item[$first], 40) : ucfirst($spec['one']).' '.($n + 1);
                @endphp
                <details class="d-thing" data-item="{{ $path }}.{{ $n }}">
                    <summary>
                        <span class="d-thing__name">{{ $title }}</span>
                        <span class="d-thing__tools">
                            <button type="button" class="d-icon" data-do="item" data-payload="{{ $j(['id' => $sid, 'field' => $path, 'do' => 'move', 'at' => $n, 'step' => -1]) }}" @disabled($n === 0) aria-label="Move up">↑</button>
                            <button type="button" class="d-icon" data-do="item" data-payload="{{ $j(['id' => $sid, 'field' => $path, 'do' => 'move', 'at' => $n, 'step' => 1]) }}" @disabled($n === count($items) - 1) aria-label="Move down">↓</button>
                            <button type="button" class="d-icon d-danger" data-do="item" data-payload="{{ $j(['id' => $sid, 'field' => $path, 'do' => 'remove', 'at' => $n]) }}" aria-label="Take out {{ $title }}">×</button>
                        </span>
                    </summary>
                    <div class="d-thing__fields">
                        @foreach ($spec['fields'] as $sub => $subSpec)
                            @include('builder.field', ['spec' => $subSpec, 'path' => $path.'.'.$n.'.'.$sub, 'value' => $item[$sub] ?? null])
                        @endforeach
                    </div>
                </details>
            @endforeach
            @if (count($items) < $max)
                <button type="button" class="d-link d-strong" data-do="item" data-payload="{{ $j(['id' => $sid, 'field' => $path, 'do' => 'add', 'at' => count($items) - 1]) }}">+ Add a {{ $spec['one'] }}</button>
            @endif
        </div>
        @break
@endswitch
