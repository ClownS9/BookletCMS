{{-- A rows field for readers: a lined table with the column names on top. --}}
@php $hasPictures = collect($columns)->contains('type', 'picture'); @endphp
<section class="rows-table" aria-labelledby="rows-{{ $handle }}">
    <h2 id="rows-{{ $handle }}" class="rows-table__title">{{ $title }}</h2>
    <div class="rows-table__scroll">
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th scope="col" @class(['rows-table__narrow' => in_array($column['type'], ['toggle', 'picture', 'number'], true)])>{{ $column['display'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($columns as $h => $column)
                            <td @class(['rows-table__num' => $column['type'] === 'number'])>
                                @switch($column['type'])
                                    @case('picture')
                                        @if ($pic = \App\Support\Blocks::picture($row[$h][0] ?? null))
                                            <img src="{{ $pic['url'] }}" alt="{{ $pic['alt'] }}" loading="lazy" decoding="async" style="object-position: {{ $pic['focus'] }}">
                                        @endif
                                        @break
                                    @case('toggle')
                                        @if ($row[$h]) <span aria-label="Yes">✓</span> @else <span class="sr-only">No</span> @endif
                                        @break
                                    @case('link')
                                        @if ($link = \App\Support\Blocks::safeLink((string) $row[$h]))
                                            <a href="{{ $link }}" @if (preg_match('#^https?://#i', $link)) rel="noopener" @endif>{{ preg_replace('#^https?://(www\.)?#i', '', rtrim($link, '/')) }}</a>
                                        @endif
                                        @break
                                    @case('textarea')
                                        {!! nl2br(e($row[$h])) !!}
                                        @break
                                    @default
                                        {{ $row[$h] }}
                                @endswitch
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
