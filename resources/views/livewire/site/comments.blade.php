<section class="margin-notes" aria-labelledby="notes-heading">
    <h2 id="notes-heading">{{ __('Notes in the margin') }} <span class="margin-notes__count">{{ $count }}</span></h2>

    @if ($comments->isNotEmpty())
        <ol class="margin-notes__list">
            @foreach ($comments as $comment)
                <li wire:key="c-{{ $comment->id }}" id="c-{{ $comment->id }}">
                    <p class="margin-notes__who"><strong>{{ $comment->author_name }}</strong> · {{ $comment->created_at->diffForHumans() }}</p>
                    <p class="margin-notes__body">{{ $comment->body }}</p>

                    @if ($comment->replies->isNotEmpty())
                        <ol class="margin-notes__replies">
                            @foreach ($comment->replies as $reply)
                                <li id="c-{{ $reply->id }}">
                                    <p class="margin-notes__who"><strong>{{ $reply->author_name }}</strong> · {{ $reply->created_at->diffForHumans() }}</p>
                                    <p class="margin-notes__body">{{ $reply->body }}</p>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    <button type="button" class="margin-notes__reply" wire:click="startReply({{ $comment->id }})"
                            x-on:click="$nextTick(() => setTimeout(() => document.getElementById('note-body')?.focus(), 60))"
                            @if ($replyTo === $comment->id) aria-pressed="true" @endif>{{ __('Reply') }}<span class="sr-only"> {{ __('to :name', ['name' => $comment->author_name]) }}</span></button>
                </li>
            @endforeach
        </ol>
    @endif

    <form wire:submit="post" class="leave-note">
        @if ($replyingTo)
            <h3>{{ __('Reply to :name', ['name' => $replyingTo->author_name]) }}</h3>
            <p class="leave-note__replying">
                “{{ \Illuminate\Support\Str::limit($replyingTo->body, 120) }}”
                <button type="button" class="leave-note__cancel" wire:click="cancelReply">{{ __('Write a new note instead') }}</button>
            </p>
        @else
            <h3>{{ __('Leave a note') }}</h3>
        @endif

        <div class="leave-note__row">
            <div class="field">
                <label for="note-name">{{ __('Name') }}</label>
                <input id="note-name" type="text" wire:model="name" autocomplete="name">
                @error('name') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="note-email">{{ __('Email') }} <span class="muted">{{ __('(not shown)') }}</span></label>
                <input id="note-email" type="email" wire:model="email" autocomplete="email">
                @error('email') <p class="field__error">{{ $message }}</p> @enderror
            </div>
        </div>

        <label class="leave-note__notify" x-show="$wire.email.includes('@')" x-cloak x-transition.opacity>
            <input type="checkbox" wire:model="notify">
            <span>{{ __('Email me when someone replies') }} <span class="muted">{{ __('(every email has a link to stop)') }}</span></span>
        </label>

        <div class="field">
            <label for="note-body">{{ $replyingTo ? __('Your reply') : __('Your note') }}</label>
            <textarea id="note-body" rows="4" wire:model="body"></textarea>
            @error('body') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="leave-note__honey" aria-hidden="true">
            <label for="note-website">{{ __('Leave this empty') }}</label>
            <input id="note-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
        </div>

        @if ($posted === 'waiting')
            <p class="leave-note__thanks" role="status">{{ $postedReply ? __('Thanks — your reply will appear once it’s approved.') : __('Thanks — your note will appear once it’s approved.') }}@if ($notify) {{ __('We’ll email you when someone replies.') }}@endif</p>
        @elseif ($posted === 'approved')
            <p class="leave-note__thanks" role="status">{{ $postedReply ? __('Thanks — your reply is up.') : __('Thanks — your note is up.') }}@if ($notify) {{ __('We’ll email you when someone replies.') }}@endif</p>
        @endif

        <x-guard form="comment" />

        <button type="submit" class="button button--ink">
            <span wire:loading.remove wire:target="post">{{ $replyingTo ? __('Post reply') : __('Post note') }}</span>
            <span wire:loading wire:target="post">{{ __('Posting…') }}</span>
        </button>
    </form>
</section>
