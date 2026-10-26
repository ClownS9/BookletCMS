{{-- Keyboard shortcuts: press ? anywhere (not while typing) --}}
@php
    $groups = [
        'Anywhere' => [
            [['mod', 'K'], 'Search everything'],
            [['/'], 'Search everything'],
            [['Alt', 'S'], 'Talk to Stacy'],
            [['Esc'], 'Close a window, or stop Stacy talking'],
            [['?'], 'This card'],
        ],
        'In the search window' => [
            [['↑', '↓'], 'Move through the results'],
            [['↵'], 'Open'],
            [['mod', '↵'], 'Open in a new tab'],
            [['Tab'], 'Next filter'],
        ],
        'While writing' => [
            [['mod', 'B'], 'Bold'],
            [['mod', 'I'], 'Italic'],
            [['mod', 'K'], 'Add a link'],
            [['mod', 'S'], 'Save'],
            [['mod', 'J'], 'Ask Stacy about the words'],
        ],
        'Talking to Stacy' => [
            [['/'], 'Her shortcuts (in the message box)'],
            [['↑', '↓'], 'Choose one'],
            [['Tab'], 'Use it'],
        ],
        'Here and there' => [
            [['↵'], 'Stick a note (Shift ↵ for a new line)'],
            [['↵'], 'Tug the lamp cord, at night'],
            [['←', '→', '↑', '↓'], 'Move a picture’s focal point'],
        ],
    ];
@endphp
<div x-data="bookletKeys()" x-on:keydown.window="key($event)" x-on:open-shortcuts.window="open = true">
    <div class="keys-sheet" x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="keys-title"
         x-on:keydown.escape.stop="open = false"
         x-transition:enter="keys-in" x-transition:enter-start="keys-in--from" x-transition:leave="keys-out" x-transition:leave-end="keys-in--from">
        <div class="keys-sheet__overlay" x-on:click="open = false"></div>
        <div class="keys-card" x-trap.noscroll="open">
            <div class="keys-card__ribbon" aria-hidden="true"></div>
            <button type="button" class="keys-card__close" x-on:click="open = false" title="{{ __('Close (Esc)') }}"><x-admin.icon name="close" size="16" /><span class="sr-only">{{ __('Close') }}</span></button>
            <p class="eyebrow">{{ __('A bookmark for your fingers') }}</p>
            <h2 id="keys-title" class="display" style="font-size: 30px; margin-top: 4px">{{ __('Keyboard shortcuts') }}</h2>

            <div class="keys-card__groups">
                @foreach ($groups as $title => $rows)
                    <section>
                        <h3 class="eyebrow">{{ $title }}</h3>
                        <dl>
                            @foreach ($rows as [$keys, $what])
                                <div>
                                    <dt>
                                        @foreach ($keys as $k)
                                            @if ($k === 'mod')
                                                <kbd class="kbd" x-text="mod"></kbd>
                                            @else
                                                <kbd class="kbd">{{ $k }}</kbd>
                                            @endif
                                        @endforeach
                                    </dt>
                                    <dd>{{ $what }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>
        </div>
    </div>
</div>
