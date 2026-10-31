{{-- The writing desk's formatting buttons (and Stacy's pen), in small groups:
     headings; words; links and pictures; lists and blocks; undo. Each adds
     Markdown at the caret, or takes it off what's picked when it's there. --}}
@if ($bodyField)
    @php
        $mod = '⌘/Ctrl';
        $btn = fn (string $kind, string $label, string $keys, string $face) => '<button type="button" x-on:click="format(\''.$kind.'\')" title="'.e($label.($keys !== '' ? ' ('.$keys.')' : '')).'" aria-label="'.e($label).'">'.$face.'</button>';
        $svg = fn (string $paths) => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>';
    @endphp
    <div class="format-bar" role="toolbar" aria-label="{{ __('Formatting') }}">
        <span class="format-bar__group">
            {!! $btn('h2', __('Heading'), '', '<span class="format-bar__h">H2</span>') !!}
            {!! $btn('h3', __('Smaller heading'), '', '<span class="format-bar__h">H3</span>') !!}
        </span>
        <span class="format-bar__group">
            {!! $btn('bold', __('Bold'), $mod.' B', '<strong>B</strong>') !!}
            {!! $btn('italic', __('Italic'), $mod.' I', '<em class="serif">I</em>') !!}
            {!! $btn('strike', __('Strikethrough'), $mod.' Shift X', '<s>S</s>') !!}
            {!! $btn('mark', __('Highlight'), $mod.' Shift H', $svg('<path d="m9 11-5 5v3h3l5-5"/><path d="m21.5 6.5-3-3a1.4 1.4 0 0 0-2 0L9 11l4 4 7.5-7.5a1.4 1.4 0 0 0 0-2"/><path d="M3 21h8" stroke-width="2.6"/>')) !!}
            {!! $btn('code', __('Code'), $mod.' E', $svg('<path d="m8 8-4 4 4 4M16 8l4 4-4 4"/>')) !!}
        </span>
        <span class="format-bar__group">
            {!! $btn('link', __('Link'), $mod.' K', $svg('<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>')) !!}
            {!! $btn('picture', __('Picture from Media'), '', $svg('<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>')) !!}
        </span>
        <span class="format-bar__group">
            {!! $btn('list', __('List'), '', $svg('<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>')) !!}
            {!! $btn('numbers', __('Numbered list'), '', $svg('<path d="M10 6h10M10 12h10M10 18h10"/><path d="M4 4.5 5 4v4M4 8h2M4 11.5c.3-.6.8-1 1.4-1 .7 0 1.1.5 1.1 1 0 1-2.5 1.8-2.5 3h2.5M4 17h1.6c.5 0 .9.4.9.9s-.4.9-.9.9H5c.6 0 1.1.4 1.1 1s-.5 1.2-1.2 1.2H4"/>')) !!}
            {!! $btn('tasks', __('Checklist'), '', $svg('<rect x="3" y="4" width="6" height="6" rx="1.2"/><path d="m4.5 7 1 1 2-2M13 7h8M13 17h8"/><rect x="3" y="14" width="6" height="6" rx="1.2"/>')) !!}
            {!! $btn('quote', __('Quote'), '', $svg('<path d="M7 7h4v4c0 3-2 5-4 6M15 7h4v4c0 3-2 5-4 6"/>')) !!}
            {!! $btn('codeblock', __('Code block'), '', $svg('<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m10 10-2 2 2 2M14 10l2 2-2 2"/>')) !!}
            {!! $btn('table', __('Table'), '', $svg('<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M10 4v16"/>')) !!}
            {!! $btn('divider', __('Divider'), '', $svg('<path d="M4 12h16"/><path d="M9 7h6M9 17h6" opacity=".45"/>')) !!}
        </span>
        <span class="format-bar__group">
            {!! $btn('undo', __('Undo'), $mod.' Z', $svg('<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>')) !!}
            {!! $btn('redo', __('Redo'), $mod.' Shift Z', $svg('<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/>')) !!}
        </span>
        @if ($stacyHelp)
            <button type="button" class="format-bar__stacy" x-on:click="pen()" :aria-expanded="help.open ? 'true' : 'false'"
                    title="{{ __('Ask Stacy to help with the words (Ctrl/⌘ J)') }}" aria-label="{{ __('Ask Stacy to help with the words') }}">
                <x-admin.icon name="stacy" size="15" /> <span>{{ __('Stacy') }}</span>
            </button>
        @endif
    </div>
@endif
