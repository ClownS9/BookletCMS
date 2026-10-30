{{-- When it was last saved, or that it's saving now --}}
<span class="saved-note" wire:loading.remove wire:target="save" x-show="!autoLabel">
    @if ($savedAt) {{ __('Saved :when', ['when' => \Illuminate\Support\Carbon::parse($savedAt)->diffForHumans()]) }} @elseif ($entryId) {{ __('Saved') }} @else {{ __('Not saved yet') }} @endif
</span>
<span class="saved-note" wire:loading.remove wire:target="save" x-show="autoLabel" x-cloak
      :class="{ 'saved-note--warn': auto === 'manual', 'saved-note--busy': auto === 'saving' }" x-text="autoLabel" aria-live="polite"></span>
<span class="saved-note" wire:loading wire:target="save">{{ __('Saving…') }}</span>
