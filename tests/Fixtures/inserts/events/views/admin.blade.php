<h2 class="heading">{{ $event ? 'Edit the event' : 'A new event' }}</h2>

<form wire:submit="act('save')" class="event-form">
    <div class="field">
        <label for="ev-title">What’s happening</label>
        <input id="ev-title" type="text" wire:model="form.title" placeholder="Kuih stall at the Sunday market">
        @error('form.title') <p class="field__error">{{ $message }}</p> @enderror
    </div>

    <div class="setting-row" style="border-top: 0; padding: 0">
        <span id="ev-allday" style="font-size: 15px; font-weight: 500">All day</span>
        <button type="button" class="switch" role="switch" aria-labelledby="ev-allday" aria-checked="{{ ($form['all_day'] ?? false) ? 'true' : 'false' }}" wire:click="$set('form.all_day', {{ ($form['all_day'] ?? false) ? 'false' : 'true' }})"></button>
    </div>

    <div class="two-up">
        <div class="field">
            <label for="ev-sd">Date</label>
            <input id="ev-sd" type="date" wire:model="form.starts_date">
            @error('form.starts_date') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        @unless ($form['all_day'] ?? false)
            <div class="field">
                <label for="ev-st">Starts at <span class="muted" style="font-weight: 400">(optional)</span></label>
                <input id="ev-st" type="time" wire:model="form.starts_time">
                @error('form.starts_time') <p class="field__error">{{ $message }}</p> @enderror
            </div>
        @endunless
    </div>
    <div class="two-up">
        <div class="field">
            <label for="ev-ed">Ends on <span class="muted" style="font-weight: 400">(if it runs over days)</span></label>
            <input id="ev-ed" type="date" wire:model="form.ends_date">
            @error('form.ends_date') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        @unless ($form['all_day'] ?? false)
            <div class="field">
                <label for="ev-et">Ends at <span class="muted" style="font-weight: 400">(optional)</span></label>
                <input id="ev-et" type="time" wire:model="form.ends_time">
                @error('form.ends_time') <p class="field__error">{{ $message }}</p> @enderror
            </div>
        @endunless
    </div>

    <div class="two-up">
        <div class="field">
            <label for="ev-venue">Where</label>
            <input id="ev-venue" type="text" wire:model="form.venue" placeholder="Gadong night market">
            @error('form.venue') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="ev-address">Address <span class="muted" style="font-weight: 400">(optional)</span></label>
            <input id="ev-address" type="text" wire:model="form.address">
            @error('form.address') <p class="field__error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="field">
        <label for="ev-about">About it <span class="muted" style="font-weight: 400">(optional)</span></label>
        <textarea id="ev-about" rows="3" wire:model="form.about"></textarea>
        @error('form.about') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label for="ev-link">A link <span class="muted" style="font-weight: 400">(tickets, a map…, optional)</span></label>
        <input id="ev-link" type="url" wire:model="form.link" placeholder="https://">
        @error('form.link') <p class="field__error">{{ $message }}</p> @enderror
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
        <button type="submit" class="btn btn--ink">{{ $event ? 'Save' : 'Add it' }}</button>
        @if ($event)
            <a class="btn btn--quiet" href="{{ route('site.insert.events.ics', $event->slug) }}">Calendar file</a>
            <button type="button" class="link-button" style="color: var(--danger); text-decoration: underline; margin-left: auto" wire:click="act('delete')" wire:confirm="Delete “{{ $event->field('title') }}”?">Delete</button>
        @endif
    </div>
</form>

<style>.event-form { display: flex; flex-direction: column; gap: 14px; }</style>
