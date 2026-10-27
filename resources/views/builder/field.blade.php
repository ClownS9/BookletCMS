{{--
    One field of the chosen section, in the drawer. Expects $spec, $path
    ('title', 'items.2.words'…), $value and $sid (the section).
--}}
@php
    $j = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // A value that couldn't go there: shown again as typed, with why, to fix.
    $why = fn (string $p) => $errors['field:'.$p] ?? null;
    $typed = fn (string $p, $v) => $why($p) !== null && request()->input('payload.field') === $p ? request()->input('payload.value') : $v;
@endphp
@switch ($spec['type'])
    @case ('text')
    @case ('url')
        <label class="d-field">
            <span>{{ $spec['label'] }}</span>
            @php $shown = $typed($path, $value); @endphp
            <input type="text" data-set="{{ $path }}" value="{{ is_scalar($shown) ? $shown : '' }}" maxlength="{{ $spec['type'] === 'url' ? 500 : ($spec['max'] ?? 200) }}"
                   @if ($why($path)) aria-invalid="true" @endif
                   @if ($spec['type'] === 'url') placeholder="{{ ! empty($spec['video']) ? 'https://www.youtube.com/watch?v=…' : 'Choose a page, or https://…' }}" autocapitalize="off" spellcheck="false" @if (empty($spec['video'])) list="pb-addresses" autocomplete="off" @endif @endif>
            @if ($why($path)) <em class="d-error">{{ $why($path) }}</em> @endif
        </label>
        @break

    @case ('words')
        <label class="d-field">
            <span>{{ $spec['label'] }}</span>
            @php $shown = $typed($path, $value); @endphp
            <textarea data-set="{{ $path }}" rows="{{ $path === 'body' ? 6 : 3 }}" maxlength="{{ $spec['max'] ?? 2000 }}" @if ($why($path)) aria-invalid="true" @endif>{{ is_scalar($shown) ? $shown : '' }}</textarea>
            <small>**bold**, *italic*, [a link](/about) · or select words on the page</small>
            @if ($why($path)) <em class="d-error">{{ $why($path) }}</em> @endif
        </label>
        @break

    @case ('link')
        <div class="d-field" role="group" aria-label="{{ $spec['label'] }}">
            <span>{{ $spec['label'] }}</span>
            <input type="text" data-set="{{ $path }}.label" aria-label="{{ $spec['label'] }}: its words" placeholder="Its words (empty: no button)" value="{{ $typed($path.'.label', $value['label'] ?? '') }}" maxlength="60">
            <input type="text" data-set="{{ $path }}.url" list="pb-addresses" autocomplete="off" aria-label="{{ $spec['label'] }}: where it goes" placeholder="Goes to: choose a page, or https://…, an email" value="{{ $typed($path.'.url', $value['url'] ?? '') }}" maxlength="500" autocapitalize="off" spellcheck="false"
                   @if ($why($path.'.url')) aria-invalid="true" @endif>
            @if ($why($path.'.url') ?? $why($path.'.label')) <em class="d-error">{{ $why($path.'.url') ?? $why($path.'.label') }}</em> @endif
        </div>
        @break

    @case ('select')
        @php $options = is_array($spec['options']) ? $spec['options'] : ($spec['options'] === 'forms' ? \App\Support\Forms::choices() : $categories); @endphp
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
                @if ($pic && ! $canMedia) <img src="{{ $pic['url'] }}" alt="" loading="lazy"> @endif
                <button type="button" class="d-btn" data-do="pick" data-payload="{{ $j(['id' => $sid, 'field' => $path]) }}">{{ $pic ? 'Change' : 'Choose from Media' }}</button>
                @if ($pic) <button type="button" class="d-link" data-do="set" data-payload="{{ $j(['id' => $sid, 'field' => $path, 'value' => '']) }}">Take it out</button> @endif
            </span>
            @if ($pic && $canMedia)
                @include('builder.picture-about', ['picPath' => $value, 'pic' => $pic])
            @endif
        </div>
        @break

    @case ('pictures')
        @php $list = array_values(array_filter((array) $value, 'is_string')); @endphp
        <div class="d-field">
            <span>{{ $spec['label'] }} · {{ count($list) }}</span>
            <button type="button" class="d-btn" data-do="pick" data-payload="{{ $j(['id' => $sid, 'field' => $path]) }}">{{ $list === [] ? 'Choose from Media' : 'Add or take out' }}</button>
            @if ($list !== [] && $canMedia)
                {{-- Each one's description, for people who can't see them --}}
                <ul class="d-alts">
                    @foreach ($list as $one)
                        @php $p = \App\PageBuilder\Fields::pictureFor($one); @endphp
                        @if ($p)
                            <li>
                                <img src="{{ $p['url'] }}" alt="" loading="lazy" style="object-position: {{ $p['focus'] }}">
                                <input type="text" data-alt="{{ $one }}" value="{{ $p['alt'] }}" maxlength="500" aria-label="Description of {{ basename($one) }}" placeholder="What’s in it (for people who can’t see it)">
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </div>
        @break

    @case ('items')
        @php $items = array_values(array_filter((array) $value, 'is_array')); $max = (int) ($spec['max_items'] ?? 20); @endphp
        <div class="d-items" role="group" aria-label="{{ $spec['label'] }}">
            <span class="d-label">{{ $spec['label'] }} · {{ count($items) }}</span>
            @foreach ($items as $n => $item)
                @php
                    // Named by what it's called (its name, heading, question…), else its first words.
                    $first = collect(['name', 'title', 'question', 'days', 'number'])->merge(array_keys($spec['fields']))->unique()
                        ->first(fn ($k) => isset($spec['fields'][$k]) && is_string($item[$k] ?? null) && trim($item[$k]) !== '' && in_array($spec['fields'][$k]['type'], ['text', 'words'], true));
                    $title = $first ? \Illuminate\Support\Str::limit($item[$first], 40) : ucfirst($spec['one']).' '.($n + 1);
                @endphp
                <details class="d-thing" data-item="{{ $path }}.{{ $n }}" @if (collect($errors)->keys()->contains(fn ($k) => str_starts_with($k, 'field:'.$path.'.'.$n.'.'))) open @endif>
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
