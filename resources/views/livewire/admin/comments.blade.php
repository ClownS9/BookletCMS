@php
    $chosen = $this->chosen;
    $tones = ['var(--sun)', 'var(--sky)', 'var(--mint)', 'var(--lilac)', 'var(--coral)', 'var(--sand)'];
@endphp

<div style="display: contents">
    {{-- Left page: the pile --}}
    <section class="page page--left" style="flex-basis: 420px">
        <p class="eyebrow">{{ \App\Support\AdminNav::chapterLabel() }} · notes in the margin</p>
        <h1 class="display display--page" style="margin-top: 8px">Comments</h1>
        <x-admin.insert-links chapter="Comments" />
        @if (in_array(request()->user()->level(), ['admin', 'editor'], true))
            @php $newMessages = \App\Support\Inbox::newCount(); @endphp
            <div class="chapter-links">
                <a href="{{ route('admin.inbox') }}" class="back" wire:navigate><x-admin.icon name="mail" size="16" /> Inbox @if ($newMessages)<span class="chapter-links__count">{{ $newMessages }} new</span>@endif →</a>
            </div>
        @endif

        <div class="filters" role="group" aria-label="Filter comments" style="margin: 22px 0 14px">
            @foreach ($statuses as $value => $label)
                <button type="button" class="pill" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">
                    {{ $label }} · {{ $counts[$value] ?? 0 }}
                </button>
            @endforeach
        </div>

        @if ($this->pile->isEmpty())
            <p class="empty-note">
                {{ $status === 'waiting' ? 'All caught up. Nothing waiting.' : 'Nothing in this pile.' }}
            </p>
        @else
            <ul class="picks">
                @foreach ($this->pile as $comment)
                    <li wire:key="comment-{{ $comment->id }}">
                        <button type="button" class="pick" wire:click="pick({{ $comment->id }})" aria-pressed="{{ $chosen?->id === $comment->id ? 'true' : 'false' }}">
                            <span class="avatar" style="--size: 36px; --tone: {{ $tones[$comment->id % count($tones)] }}">{{ mb_strtoupper(mb_substr($comment->author_name, 0, 1)) }}</span>
                            <span style="flex: 1 1 auto; min-width: 0">
                                <span style="display: block; font-size: 14px; font-weight: 600">{{ $comment->author_name }}
                                    <span style="font-weight: 400; color: var(--faint)">{{ $comment->parent_id ? 'replied to '.($comment->parent?->author_name ?? 'a note').' on' : 'on' }} {{ $titles[$comment->entry_id] ?? '' }}</span>
                                </span>
                                <span class="serif" style="display: block; margin-top: 3px; font-size: 16px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $comment->body }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the note --}}
    <section class="page page--right" style="--ribbon: var(--rose)">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($chosen)
            <p class="ribbon-clear" style="font-size: 14px; color: var(--faint)">
                On
                @if ($entry)
                    <a href="{{ route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()]) }}" class="serif" style="font-style: italic; font-size: 17px" wire:navigate>{{ $entry->title() }}</a>
                @else
                    <span class="serif" style="font-style: italic; font-size: 17px">a removed entry</span>
                @endif
                · {{ $chosen->created_at->diffForHumans() }}
            </p>

            @if ($chosen->parent_id && $chosen->parent)
                <p class="reply-context">In reply to <strong>{{ $chosen->parent->author_name }}</strong>: “{{ \Illuminate\Support\Str::limit($chosen->parent->body, 160) }}”</p>
            @endif

            <div class="sticky-note">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px">
                    <span class="avatar" style="--size: 44px; --tone: {{ $tones[$chosen->id % count($tones)] }}">{{ mb_strtoupper(mb_substr($chosen->author_name, 0, 1)) }}</span>
                    <span style="min-width: 0">
                        <span style="display: block; font-weight: 600">{{ $chosen->author_name }}</span>
                        @if ($chosen->author_email) <span style="display: block; font-size: 13px; color: #6B655A">{{ $chosen->author_email }}@if ($chosen->notify) · wants an email when someone replies @endif</span> @endif
                    </span>
                    <span class="sticker sticker--{{ ['waiting' => 'rose', 'approved' => 'mint', 'spam' => 'sand'][$chosen->status] }}" style="margin-left: auto; --tilt: 3deg">{{ ucfirst($chosen->status) }}</span>
                </div>
                <p class="serif" style="font-size: 22px; line-height: 1.5; white-space: pre-line">“{{ $chosen->body }}”</p>
            </div>

            @if ($chosen->replies->isNotEmpty())
                <ol style="list-style: none; margin: 26px 0 0; padding: 0 0 0 20px; box-shadow: inset 2px 0 0 var(--rose); display: flex; flex-direction: column; gap: 14px">
                    @foreach ($chosen->replies as $reply)
                        <li>
                            <p style="font-size: 14px"><strong>{{ $reply->author_name }}</strong> <span class="muted">· {{ $reply->created_at->diffForHumans() }}</span>
                                @if ($reply->status !== 'approved') <button type="button" class="link-button" style="min-height: 0; padding: 0; text-decoration: underline" wire:click="open({{ $reply->id }})">{{ $reply->status === 'spam' ? 'In spam' : 'Waiting' }}</button> @endif</p>
                            <p class="serif" style="margin-top: 4px; font-size: 18px; line-height: 1.55; white-space: pre-line">{{ $reply->body }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif

            <div style="margin-top: 28px; display: flex; gap: 10px; flex-wrap: wrap">
                @if ($chosen->status !== 'approved')
                    <button type="button" class="btn btn--ink" wire:click="setChosenStatus('approved')">Approve</button>
                @endif
                @if ($chosen->status !== 'spam')
                    <button type="button" class="btn" wire:click="setChosenStatus('spam')">Mark as spam</button>
                @endif
                @if ($chosen->status !== 'waiting')
                    <button type="button" class="btn btn--quiet" wire:click="setChosenStatus('waiting')">Back to waiting</button>
                @endif
                <button type="button" class="btn btn--danger" wire:click="deleteChosen" wire:confirm="Move this comment and its replies to the bin?">Delete</button>
            </div>

            @if ($chosen->status !== 'spam')
                <form wire:submit="sendReply" style="margin-top: auto; padding-top: 28px">
                    <label for="reply" class="field__label" style="display: block; margin-bottom: 8px">Write back</label>
                    <textarea id="reply" rows="3" class="lined" wire:model="reply" placeholder="Your reply is posted under the comment"></textarea>
                    @error('reply') <p class="field__error">{{ $message }}</p> @enderror
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 14px; flex-wrap: wrap">
                        <button type="submit" class="btn">{{ $chosen->status === 'waiting' ? 'Approve & reply' : 'Send reply' }}</button>
                        <x-admin.folio side="right" style="margin: 0; padding: 0" />
                    </div>
                </form>
            @else
                <x-admin.folio side="right" />
            @endif
        @else
            <p class="empty-note" style="max-width: 460px">Pick a note on the left to read it here.</p>
            <x-admin.folio side="right" />
        @endif
    </section>
</div>
