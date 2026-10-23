@if (! $album)
    <h2 class="heading">Albums</h2>
    <p class="empty-note" style="max-width: 460px">Pick an album on the left, or make a new one. Then put <code>[[gallery: its-name]]</code> anywhere in a post or page.</p>
@else
    @php $chosen = (array) $album->field('pictures', []); @endphp
    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap">
        <h2 class="heading">{{ $album->field('title') }}</h2>
        <button type="button" class="link-button" wire:click="act('close')">Done</button>
    </div>
    <p class="field__hint" style="margin: 0">In a post or page: <code>[[gallery: {{ $album->slug }}]]</code></p>

    <form wire:submit="act('save')" class="gallery-details">
        <div class="field">
            <label for="album-name">Name</label>
            <input id="album-name" type="text" wire:model="form.title">
            @error('form.title') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="album-about">A line about it <span class="muted" style="font-weight: 400">(optional, shown above the pictures)</span></label>
            <textarea id="album-about" rows="2" wire:model="form.about"></textarea>
            @error('form.about') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn" style="align-self: flex-start">Save</button>
    </form>

    @if ($chosen !== [])
        <h3 class="eyebrow section-label" style="margin: 0">In this album, in order · {{ count($chosen) }}</h3>
        <ol class="gallery-order">
            @foreach ($chosen as $i => $path)
                @if (isset($pictures[$path]))
                    <li wire:key="in-{{ md5($path) }}">
                        <img src="{{ $pictures[$path]['thumb'] }}" alt="{{ $pictures[$path]['alt'] }}" loading="lazy">
                        <span class="gallery-order__do">
                            <button type="button" class="link-button" wire:click="act('move', @js(['path' => $path, 'step' => -1]))" @disabled($i === 0) aria-label="Move earlier">←</button>
                            <button type="button" class="link-button" wire:click="act('move', @js(['path' => $path, 'step' => 1]))" @disabled($i === count($chosen) - 1) aria-label="Move later">→</button>
                        </span>
                    </li>
                @endif
            @endforeach
        </ol>
    @endif

    <h3 class="eyebrow section-label" style="margin: 0">Pictures in Media · press to add or take out</h3>
    @if ($pictures === [])
        <p class="empty-note">There are no pictures in Media yet. <a href="{{ route('admin.media') }}" wire:navigate>Add some →</a></p>
    @else
        <ul class="gallery-pick">
            @foreach ($pictures as $path => $pic)
                @php $in = in_array($path, $chosen, true); @endphp
                <li wire:key="pick-{{ md5($path) }}">
                    <button type="button" wire:click="act('picture', @js($path))" aria-pressed="{{ $in ? 'true' : 'false' }}"
                            aria-label="{{ ($pic['alt'] ?: basename($path)) }}{{ $in ? ', in the album' : '' }}">
                        <img src="{{ $pic['thumb'] }}" alt="" loading="lazy">
                        @if ($in) <span class="gallery-pick__n" aria-hidden="true">{{ array_search($path, $chosen, true) + 1 }}</span> @endif
                    </button>
                    @unless ($pic['alt'])
                        <span class="gallery-pick__warn">No description</span>
                    @endunless
                </li>
            @endforeach
        </ul>
    @endif

    <button type="button" class="link-button" style="align-self: flex-start; color: var(--danger); text-decoration: underline"
            wire:click="act('delete')" wire:confirm="Delete the album “{{ $album->field('title') }}”? Its pictures stay in Media.">Delete this album</button>
@endif

<style>
    .gallery-new { display: flex; flex-direction: column; gap: 8px; padding-top: 16px; border-top: 1px solid var(--rule); }
    .gallery-details { display: flex; flex-direction: column; gap: 12px; }
    .gallery-order { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 10px; }
    .gallery-order li { display: flex; flex-direction: column; align-items: center; gap: 2px; }
    .gallery-order img { width: 72px; height: 72px; object-fit: cover; border-radius: 6px; }
    .gallery-order__do { display: flex; gap: 6px; }
    .gallery-order__do .link-button { min-height: 28px; min-width: 28px; padding: 0; font-size: 15px; }
    .gallery-pick { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; }
    .gallery-pick li { display: flex; flex-direction: column; gap: 4px; }
    .gallery-pick button { position: relative; display: block; width: 100%; aspect-ratio: 1; padding: 0; border: 2px solid transparent; border-radius: 8px; overflow: hidden; background: var(--paper-3); cursor: pointer; }
    .gallery-pick button img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .gallery-pick button[aria-pressed="true"] { border-color: var(--ink); box-shadow: 0 0 0 3px color-mix(in srgb, var(--sky) 55%, transparent); }
    .gallery-pick__n { position: absolute; top: 6px; left: 6px; min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px; background: var(--ink); color: var(--paper); font-size: 12px; font-weight: 600; display: grid; place-items: center; }
    .gallery-pick__warn { font-size: 11.5px; color: var(--danger); }
</style>
<style>.gallery-order__do button:disabled { opacity: .3; cursor: default; }</style>
