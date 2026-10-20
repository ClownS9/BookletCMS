{{-- Picture sizes (Statamic's Glide presets): the shapes every picture can be had in. Admins only. --}}
<section class="sizes-panel" aria-labelledby="sizes-title">
    <h2 id="sizes-title" class="eyebrow section-label" style="margin: 0">Picture sizes</h2>
    <p class="field__hint">Each picture can be had in these shapes, cut around its focal point. Posts’ cards use <strong>Card</strong>, shared links use <strong>Share</strong>, and the Picture cut-out offers them all. Themes ask for one by name: <code>$site->crop($entry, 'hero')</code>.</p>

    <ol class="sizes-panel__list" role="list">
        @foreach ($sizeRows as $i => $row)
            @php $w = (int) $row['width']; $h = (int) $row['height']; @endphp
            <li class="sizes-panel__row" wire:key="size-{{ $row['key'] }}">
                <span class="sizes-panel__shape" aria-hidden="true" style="aspect-ratio: {{ max(16, $w) }} / {{ max(16, $h) }}"></span>
                <span class="sizes-panel__fields">
                    <label class="sr-only" for="size-label-{{ $i }}">Name</label>
                    <input id="size-label-{{ $i }}" type="text" class="input" wire:model.blur="sizeRows.{{ $i }}.label" placeholder="Name" maxlength="40">
                    <span class="sizes-panel__dims">
                        <label class="sr-only" for="size-w-{{ $i }}">Width</label>
                        <input id="size-w-{{ $i }}" type="number" class="input" min="16" max="{{ \App\Support\Crops::MAX_SIDE }}" wire:model.live.debounce.400ms="sizeRows.{{ $i }}.width">
                        <span aria-hidden="true">×</span>
                        <label class="sr-only" for="size-h-{{ $i }}">Height</label>
                        <input id="size-h-{{ $i }}" type="number" class="input" min="16" max="{{ \App\Support\Crops::MAX_SIDE }}" wire:model.live.debounce.400ms="sizeRows.{{ $i }}.height">
                        <span class="sizes-panel__ratio">{{ $w >= 16 && $h >= 16 ? \App\Support\Crops::ratio($w, $h) : '' }}</span>
                    </span>
                    <code class="sizes-panel__handle">{{ $row['handle'] ?: \Illuminate\Support\Str::snake(\Illuminate\Support\Str::slug($row['label'] ?: 'size', ' ')) }}</code>
                </span>
                <button type="button" class="icon-btn icon-btn--sm icon-btn--danger" wire:click="removeSize({{ $i }})" aria-label="Remove {{ $row['label'] ?: 'this size' }}">×</button>
            </li>
            @error("sizeRows.$i.label") <p class="field__error">{{ $message }}</p> @enderror
            @error("sizeRows.$i.width") <p class="field__error">{{ $message }}</p> @enderror
            @error("sizeRows.$i.height") <p class="field__error">{{ $message }}</p> @enderror
        @endforeach
    </ol>
    @error('sizeRows') <p class="field__error">{{ $message }}</p> @enderror

    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
        @if (count($sizeRows) < \App\Support\Crops::MAX_SIZES)
            <button type="button" class="btn btn--dashed btn--sm" wire:click="addSize">+ Add a size</button>
        @endif
        <button type="button" class="btn btn--ink btn--sm" wire:click="saveSizes">Save sizes</button>
        <button type="button" class="link-button" style="font-size: 13px" wire:click="resetSizes" wire:confirm="Go back to Booklet’s own sizes (Card, Hero, Square, Tall, Share)?">Booklet’s own sizes</button>
    </div>
    <p class="field__hint">A size someone’s picture cut-out uses stays as it is if you remove it here: that picture just shows as it is.</p>
</section>
