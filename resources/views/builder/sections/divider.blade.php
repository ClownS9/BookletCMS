{{-- A quiet break: a line, a short line, three dots, or just space. --}}
@php $style = (string) $f->get('style', 'line'); @endphp
@if ($style === 'dots')
    <p class="pb-divider pb-divider--dots" role="separator"><span></span><span></span><span></span></p>
@elseif ($style === 'space')
    <div class="pb-divider pb-divider--space" aria-hidden="true">@if ($f->live)<span class="pb-divider__hint">Space</span>@endif</div>
@else
    <hr class="pb-divider pb-divider--{{ $style === 'short' ? 'short' : 'line' }}">
@endif
