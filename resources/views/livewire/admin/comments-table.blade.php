@php
    $chosen = $drawer ? $this->chosen : null;
    $pile = $this->pile;
    $tones = ['var(--sun)', 'var(--sky)', 'var(--mint)', 'var(--lilac)', 'var(--coral)', 'var(--sand)'];
    $pageIds = $pile->getCollection()->pluck('id')->all();
    $stickers = ['waiting' => 'review', 'approved' => 'published', 'spam' => 'draft'];
@endphp
<div style="display: contents">
    {{-- Comments in the Dashboard layout: one table of notes, with tabs, search,
         ticks for many at once, a menu on each row, and a drawer to read and reply. --}}
    <section class="page page--single page--dash" aria-labelledby="comments-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="comments-title">{{ __('Comments') }}</h1>
                    <p>{{ __('Notes readers leave in the margin. New ones wait here until someone approves them.') }}</p>
                </div>
                @if (in_array(request()->user()->level(), ['admin', 'editor'], true))
                    @php $newMessages = \App\Support\Inbox::newCount(); @endphp
                    <a href="{{ route('admin.inbox') }}" class="btn" wire:navigate><x-admin.icon name="mail" size="16" /> {{ __('Inbox') }}@if ($newMessages) <b class="dash-filter-toggle__n">{{ $newMessages }}</b>@endif</a>
                @endif
            </div>
            <x-admin.insert-links chapter="Comments" />

            <section class="dash-card" aria-label="{{ __('Comments') }}">
                <div class="dash-tabs" role="group" aria-label="{{ __('Filter comments') }}">
                    @foreach ($statuses as $value => $label)
                        <button type="button" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">
                            {{ __($label) }} <b>{{ number_format($counts[$value] ?? 0) }}</b>
                        </button>
                    @endforeach
                </div>

                @if ($ticked === [])
                    <div class="dash-tools">
                        <div class="search">
                            <x-admin.icon name="search" size="16" />
                            <label for="comment-search" class="sr-only">{{ __('Search comments') }}</label>
                            <input id="comment-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('Search by name, email or words') }}">
                        </div>
                    </div>
                @else
                    <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                        <strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
                        @if ($status !== 'approved')
                            <button type="button" class="btn btn--sm" wire:click="setStatusTicked('approved')">{{ __('Approve') }}</button>
                        @endif
                        @if ($status !== 'spam')
                            <button type="button" class="btn btn--sm" wire:click="setStatusTicked('spam')">{{ __('Mark as spam') }}</button>
                        @endif
                        @if ($status !== 'waiting')
                            <button type="button" class="btn btn--sm" wire:click="setStatusTicked('waiting')">{{ __('Back to waiting') }}</button>
                        @endif
                        <button type="button" class="btn btn--danger btn--sm" wire:click="binTicked" wire:confirm="{{ trans_choice('Move :count comment and its replies to the bin?|Move :count comments and their replies to the bin?', count($ticked)) }}">{{ __('Move to the bin') }}</button>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('ticked', [])">{{ __('Clear') }}</button>
                    </div>
                @endif

                @if ($pile->isEmpty())
                    <p class="dash-empty">
                        @if (trim($search) !== '')
                            {{ __('Nothing matches. Try other words.') }} <button type="button" class="link-button" wire:click="$set('search', '')">{{ __('Clear') }}</button>
                        @else
                            {{ $status === 'waiting' ? __('All caught up. Nothing waiting.') : __('Nothing in this pile.') }}
                        @endif
                    </p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table dash-table--comments">
                            <thead>
                                <tr>
                                    <th scope="col" class="dash-table__tick">
                                        <input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked($pageIds !== [] && array_diff($pageIds, $ticked) === [])
                                               aria-label="{{ __('Choose all on this page') }}">
                                    </th>
                                    <th scope="col">{{ __('From') }}</th>
                                    <th scope="col">{{ __('Comment') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Left on') }}</th>
                                    <th scope="col" class="is-wide">{{ __('When') }}</th>
                                    <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pile as $comment)
                                    @php $ticks = in_array($comment->id, $ticked, true); $entryTitle = $titles[$comment->entry_id] ?? ''; @endphp
                                    <tr wire:key="c-{{ $comment->id }}" @class(['is-ticked' => $ticks, 'is-open' => $chosen?->id === $comment->id])>
                                        <td class="dash-table__tick">
                                            <input type="checkbox" wire:model.live="ticked" value="{{ $comment->id }}" aria-label="{{ __('Choose the comment from :name', ['name' => $comment->author_name]) }}">
                                        </td>
                                        <td class="dash-table__who">
                                            <span class="dash-person">
                                                <span class="avatar" style="--size: 30px; --tone: {{ $tones[$comment->id % count($tones)] }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($comment->author_name, 0, 1)) }}</span>
                                                <span style="min-width: 0">
                                                    <span class="dash-table__name">{{ $comment->author_name }}</span>
                                                    @if ($comment->author_email)<span class="dash-table__sub">{{ $comment->author_email }}</span>@endif
                                                </span>
                                            </span>
                                        </td>
                                        <td class="dash-table__main">
                                            <button type="button" class="dash-table__open" wire:click="pick({{ $comment->id }})">
                                                @if ($comment->parent_id)<span class="dash-table__sub">{{ __('Reply to :name', ['name' => $comment->parent?->author_name ?? __('a note')]) }}</span>@endif
                                                <span class="dash-table__quote">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $comment->body), 140) }}</span>
                                            </button>
                                        </td>
                                        <td class="is-wide dash-table__on">{{ $entryTitle }}</td>
                                        <td class="is-quiet is-wide" title="{{ $comment->created_at->translatedFormat('j M Y, g:i A') }}">{{ $comment->created_at->diffForHumans() }}</td>
                                        <td class="dash-table__actions">
                                            @if ($comment->status !== 'approved')
                                                <button type="button" class="btn btn--sm" wire:click="setStatusFor({{ $comment->id }}, 'approved')">{{ __('Approve') }}</button>
                                            @endif
                                            <div class="dash-more" x-data="bookletRowMenu()" x-on:keydown.escape="close()" x-on:click.outside="close()">
                                                <button type="button" class="icon-btn" x-ref="button" x-on:click="toggle()" :aria-expanded="open.toString()" aria-expanded="false" aria-label="{{ __('More for the comment from :name', ['name' => $comment->author_name]) }}">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                                                </button>
                                                <div class="dash-more__menu" x-ref="menu" x-show="open" x-cloak :style="place" x-transition.opacity.duration.120ms>
                                                    <button type="button" x-on:click="open = false" wire:click="pick({{ $comment->id }})">{{ $comment->status === 'spam' ? __('Read') : __('Read and reply') }}</button>
                                                    @if ($comment->status !== 'spam')
                                                        <button type="button" x-on:click="open = false" wire:click="setStatusFor({{ $comment->id }}, 'spam')">{{ __('Mark as spam') }}</button>
                                                    @endif
                                                    @if ($comment->status !== 'waiting')
                                                        <button type="button" x-on:click="open = false" wire:click="setStatusFor({{ $comment->id }}, 'waiting')">{{ __('Back to waiting') }}</button>
                                                    @endif
                                                    @if ($entryTitle !== '' && ($e = \App\Booklet\Facades\Booklet::entries()->find($comment->entry_id)))
                                                        <a href="{{ route('admin.entries.edit', [$e->collectionHandle(), $e->id()]) }}" wire:navigate>{{ __('Open the post') }}</a>
                                                    @endif
                                                    <button type="button" class="is-danger" x-on:click="open = false" wire:click="binOne({{ $comment->id }})" wire:confirm="{{ __('Move this comment and its replies to the bin?') }}">{{ __('Move to the bin') }}</button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-admin.pager :of="$pile" :sizes="$pageSizes" class="dash-foot" id="comments-pager" always />
                @endif
            </section>
        </div>
    </section>

    @if ($chosen)
        <x-admin.drawer :title="__('Comment from :name', ['name' => $chosen->author_name])">
            <p class="drawer__meta">
                {{ __('Left on') }}
                @if ($entry)
                    <a href="{{ route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()]) }}" wire:navigate>{{ $entry->title() }}</a>
                @else
                    {{ __('a removed entry') }}
                @endif
                · {{ $chosen->created_at->diffForHumans() }}
                <span class="dash-status dash-status--{{ $stickers[$chosen->status] }}"><i aria-hidden="true"></i>{{ __(\App\Livewire\Admin\Comments::STATUSES[$chosen->status]) }}</span>
            </p>

            @if ($chosen->parent_id && $chosen->parent)
                <p class="reply-context">{{ __('In reply to') }} <strong>{{ $chosen->parent->author_name }}</strong>: “{{ \Illuminate\Support\Str::limit($chosen->parent->body, 160) }}”</p>
            @endif

            <div class="drawer-note">
                <div class="drawer-note__who">
                    <span class="avatar" style="--size: 40px; --tone: {{ $tones[$chosen->id % count($tones)] }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($chosen->author_name, 0, 1)) }}</span>
                    <span style="min-width: 0">
                        <strong>{{ $chosen->author_name }}</strong>
                        @if ($chosen->author_email)<small>{{ $chosen->author_email }}@if ($chosen->notify) · {{ __('wants an email when someone replies') }}@endif</small>@endif
                    </span>
                </div>
                <p class="drawer-note__body">{{ $chosen->body }}</p>
            </div>

            @if ($chosen->replies->isNotEmpty())
                <ol class="drawer-thread" role="list">
                    @foreach ($chosen->replies as $reply)
                        <li>
                            <p><strong>{{ $reply->author_name }}</strong> <span class="muted">· {{ $reply->created_at->diffForHumans() }}</span>
                                @if ($reply->status !== 'approved') <button type="button" class="link-button" wire:click="open({{ $reply->id }})">{{ $reply->status === 'spam' ? __('In spam') : __('Waiting') }}</button> @endif</p>
                            <p class="drawer-thread__body">{{ $reply->body }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif

            <div class="drawer__actions">
                @if ($chosen->status !== 'approved')
                    <button type="button" class="btn btn--ink" wire:click="setChosenStatus('approved')">{{ __('Approve') }}</button>
                @endif
                @if ($chosen->status !== 'spam')
                    <button type="button" class="btn" wire:click="setChosenStatus('spam')">{{ __('Mark as spam') }}</button>
                @endif
                @if ($chosen->status !== 'waiting')
                    <button type="button" class="btn btn--quiet" wire:click="setChosenStatus('waiting')">{{ __('Back to waiting') }}</button>
                @endif
                <button type="button" class="btn btn--danger" wire:click="deleteChosen" wire:confirm="{{ __('Move this comment and its replies to the bin?') }}">{{ __('Delete') }}</button>
            </div>

            @if ($chosen->status !== 'spam')
                <form wire:submit="sendReply" class="drawer__reply">
                    <label for="reply" class="field__label">{{ __('Write back') }}</label>
                    <textarea id="reply" rows="4" class="input" wire:model="reply" placeholder="{{ __('Your reply is posted under the comment') }}"></textarea>
                    @error('reply') <p class="field__error">{{ $message }}</p> @enderror
                    <button type="submit" class="btn" style="align-self: flex-start">{{ $chosen->status === 'waiting' ? __('Approve & reply') : __('Send reply') }}</button>
                </form>
            @endif
        </x-admin.drawer>
    @endif
</div>
