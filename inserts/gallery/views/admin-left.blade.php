@if ($albums->isEmpty())
    <p class="empty-note">No albums yet. Make one, then pick its pictures from Media.</p>
@else
    <ul class="picks" aria-label="Albums">
        @foreach ($albums as $a)
            <li wire:key="album-{{ $a->id }}">
                <button type="button" class="pick" wire:click="act('open', {{ $a->id }})" aria-pressed="{{ $album?->id === $a->id ? 'true' : 'false' }}">
                    <span class="pick__title" style="font-size: 18px">{{ $a->field('title') }}</span>
                    <span class="pick__meta">{{ count((array) $a->field('pictures', [])) }} {{ str('picture')->plural(count((array) $a->field('pictures', []))) }} · <code>[[gallery: {{ $a->slug }}]]</code></span>
                </button>
            </li>
        @endforeach
    </ul>
@endif

@unless ($album)
    <form wire:submit="act('create')" class="gallery-new">
        <label for="album-title" class="field__label">A new album</label>
        <div style="display: flex; gap: 8px; flex-wrap: wrap">
            <input id="album-title" class="input" type="text" wire:model="form.title" placeholder="Summer at the stall" style="flex: 1 1 200px">
            <button type="submit" class="btn btn--ink">Make it</button>
        </div>
        @error('form.title') <p class="field__error">{{ $message }}</p> @enderror
    </form>
@endunless
