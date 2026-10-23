<form wire:submit="act('create')" class="pbx-new">
    <div class="field">
        <label for="pb-new-title">A new page</label>
        <input id="pb-new-title" type="text" wire:model="form.title" placeholder="{{ $form['starter'] === 'entry' ? 'Its name (empty: the same as the page’s)' : 'Our spring offer' }}" maxlength="120">
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
        @if ($entries !== [])
            <label class="pbx-starter">
                <input type="radio" name="pb-starter" value="entry" wire:model.live="form.starter">
                <span><b>One of your pages</b><small>Its title, words and pictures to start from. When you publish, it takes that page’s place</small></span>
            </label>
        @endif
    </fieldset>

    @if ($form['starter'] === 'entry')
        <div class="field">
            <label for="pb-from">Which page</label>
            <select id="pb-from" wire:model.live="form.from">
                <option value="">Choose…</option>
                @foreach ($entries as $group => $list)
                    <optgroup label="{{ $group }}">
                        @foreach ($list as $id => $words)
                            <option value="{{ $id }}">{{ $words }}{{ $id === $homeId ? ' · your home page' : '' }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @error('form.from') <p class="field__error">{{ $message }}</p> @enderror
            @if ($form['from'] === '')
                <p class="field__hint">Nothing changes on the site until you publish the built page.</p>
            @elseif ($form['from'] === $homeId)
                <p class="field__hint">{{ $homeWorks
                    ? 'When you publish, the built page becomes your home page. Your page stays as it is, and you can put it back any time.'
                    : 'Taking the place of your home page needs Booklet 1.2.' }}</p>
            @else
                <p class="field__hint">When you publish, the built page takes its address and its place in your menus. Your page is kept as a draft, and you can put it back any time.</p>
            @endif
        </div>
    @endif
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
