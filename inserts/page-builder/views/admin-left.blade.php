<form wire:submit="act('create')" class="pbx-new">
    <div class="field">
        <label for="pb-new-title">A new page</label>
        <input id="pb-new-title" type="text" wire:model="form.title" placeholder="Our spring offer" maxlength="120">
        @error('form.title') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    <fieldset class="pbx-starters">
        <legend class="field__label">Start from</legend>
        @foreach ($starters as $key => $starter)
            <label class="pbx-starter">
                <input type="radio" name="pb-starter" value="{{ $key }}" wire:model="form.starter">
                <span><b>{{ $starter['name'] }}</b><small>{{ $starter['about'] }}</small></span>
            </label>
        @endforeach
    </fieldset>
    <button type="submit" class="btn btn--ink" style="align-self: flex-start">Make it and start building</button>
    <p class="field__hint" style="margin: 0">It opens on the site, ready to build. Nobody else sees it until you publish it.</p>
</form>

<style>
    .pbx-new { display: flex; flex-direction: column; gap: 14px; padding-top: 16px; border-top: 1px solid var(--rule); }
    .pbx-starters { border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    .pbx-starters legend { margin-bottom: 6px; }
    .pbx-starter { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--rule); border-radius: 10px; cursor: pointer; }
    .pbx-starter:has(input:checked) { border-color: var(--ink); background: var(--chosen); }
    .pbx-starter input { margin-top: 3px; accent-color: var(--ink); }
    .pbx-starter b { display: block; font-size: 14px; }
    .pbx-starter small { display: block; font-size: 12px; color: var(--faint); line-height: 1.4; }
</style>
