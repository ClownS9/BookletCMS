{{-- Night reading's desk lamp: light layers behind the desk, and a pull cord
     hanging beside the book. Pull it (or press Enter on it) to switch the lamp.
     Behaviour lives in booklet.js (bookletLamp); only shown in night reading. --}}
<div class="lamp" data-lamp>
    <div class="lamp__glow lamp__glow--warm" aria-hidden="true"></div>
    <div class="lamp__glow lamp__glow--moon" aria-hidden="true"></div>

    <div class="lamp__cord" data-lamp-cord>
        <svg class="lamp__string" aria-hidden="true"><path data-lamp-string d="" /></svg>
        <button type="button" class="lamp__bead" data-lamp-bead role="switch" aria-checked="true"
                aria-label="{{ __('Desk lamp') }}" title="{{ __('Pull for the lamp') }}">
            <span class="lamp__cap" aria-hidden="true"></span>
        </button>
    </div>
</div>
