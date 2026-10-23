@if ($made)
    {{-- Just made: off to the page itself, to build it there. --}}
    <p class="empty-note" x-init="window.location.href = @js(url('/'.$made->slug).'?edit=1')">
        Opening “{{ $made->field('title') }}” on the site… <a href="{{ url('/'.$made->slug) }}?edit=1">Open it</a>
    </p>
@endif

<h2 class="heading">Built pages</h2>
@if ($pages->isEmpty())
    <p class="empty-note" style="max-width: 480px">No pages yet. Name one on the left and choose what to start from. You build it on the site itself: click words to type, add sections, then publish.</p>
@else
    <ul class="pbx-pages">
        @foreach ($pages as $p)
            @php [$state, $words] = $statusOf($p); $count = count(array_filter((array) $p->field('draft', []), 'is_array')); @endphp
            <li class="pbx-page" wire:key="page-{{ $p->id }}">
                <span class="pbx-page__name">{{ $p->field('title') }}</span>
                <span class="pbx-page__meta"><code>{{ $addressOf($p) }}</code> · {{ $count }} {{ str('section')->plural($count) }}</span>
                <span class="pbx-page__tags">
                    <span @class(['pbx-state', 'pbx-state--'.$state])>{{ $words }}</span>
                    @if ($addressOf($p) === '/') <span class="pbx-state pbx-state--home">Home page</span> @endif
                </span>
                <span class="pbx-page__do">
                    <a class="btn btn--ink" href="{{ url('/'.$p->slug) }}?edit=1">Edit on the page</a>
                    <button type="button" class="link-button" wire:click="act('delete', {{ $p->id }})" wire:confirm="Delete “{{ $p->field('title') }}”? This can’t be undone.">Delete</button>
                </span>
            </li>
        @endforeach
    </ul>
    <p class="field__hint">Any built page also fits into a post or page: <code>[[builder: its-address]]</code>.</p>
@endif

<style>
    .pbx-pages { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
    .pbx-page { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 16px; border: 1px solid var(--rule); border-radius: 12px; background: var(--paper-3); }
    .pbx-page__name { font-family: var(--serif); font-size: 20px; line-height: 1.2; }
    .pbx-page__meta { font-size: 13px; color: var(--faint); }
    .pbx-page__tags { display: flex; gap: 6px; flex-wrap: wrap; }
    .pbx-page__do { display: flex; gap: 14px; align-items: center; margin-top: 8px; }
    .pbx-page__do .link-button { font-size: 14px; color: var(--danger); text-decoration: underline; }
    .pbx-state { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--hover); color: var(--ink); }
    .pbx-state--live { background: color-mix(in srgb, var(--mint) 60%, transparent); }
    .pbx-state--changed { background: color-mix(in srgb, var(--sun) 65%, transparent); }
    .pbx-state--home { background: color-mix(in srgb, var(--lilac) 60%, transparent); }
</style>
