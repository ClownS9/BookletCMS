{{-- The entry's address --}}
<div class="{{ $slugClass ?? 'field' }}">
    <label for="entry-slug">{{ ($slugClass ?? null) ? __('Address') : __('Slug') }}</label>
    <div class="slug">
        <span class="muted">{{ $routePrefix ?: '/' }}</span>
        <input id="entry-slug" type="text" wire:model.blur="slug" placeholder="{{ __('made-from-the-title') }}">
    </div>
    @error('slug') <p class="field__error">{{ $message }}</p> @enderror
</div>
