<div style="display: contents">
    {{-- Left page: categories, like a book's index --}}
    <section class="page page--left" style="flex-basis: 400px">
        <a href="{{ route('admin.entries', 'posts') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Posts</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
        <h1 class="display display--page" style="margin-top: 8px">Index</h1>
        <p class="lede" style="margin-top: 12px; font-size: 15px">Categories are the sections of your blog. Each post sits in one. Pick one to rename or remove it.</p>

        <h2 class="eyebrow section-label section-label--gap">Categories</h2>
        @if ($categories->isEmpty())
            <p class="empty-note">No categories yet.</p>
        @else
            <ul class="index-list">
                @foreach ($categories as $i => $category)
                    <li wire:key="cat-{{ $category->slug() }}">
                        <button type="button" wire:click="choose('categories', '{{ $category->slug() }}')"
                                aria-pressed="{{ $chosen && $chosen->taxonomy() === 'categories' && $chosen->slug() === $category->slug() ? 'true' : 'false' }}">
                            <span class="index-list__name">
                                <span class="dot" style="--dot: var(--{{ ['coral', 'lilac', 'mint', 'sky', 'sun', 'rose', 'sand'][$i % 7] }}); --tilt: {{ $i % 2 ? '6deg' : '-6deg' }}"></span>
                                {{ $category->title() }}
                            </span>
                            <span class="index-list__lead" aria-hidden="true"></span>
                            <span class="index-list__count">{{ $count($category) }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canManage)
            <form wire:submit="addCategory" style="margin-top: 22px; display: flex; gap: 10px; align-items: flex-start">
                <div class="field" style="flex: 1 1 auto">
                    <label for="new-cat" class="sr-only">New category</label>
                    <input id="new-cat" type="text" wire:model="newCategory" placeholder="New category" autocomplete="off">
                    @error('newCategory') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn--ink">Add</button>
            </form>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: tags as stickers, and the chosen one --}}
    <section class="page page--right" style="--ribbon: var(--rose)">
        <div class="ribbon" aria-hidden="true"></div>

        <h2 class="heading">Tags</h2>
        <p style="margin-top: 6px; font-size: 14px; color: var(--muted)">Bigger stickers are used more. Pick one to rename, merge or remove it.</p>

        @if ($tags->isEmpty())
            <p class="empty-note">No tags yet. Add some to a post in its margin notes.</p>
        @else
            <div class="tag-cloud">
                @foreach ($tags as $i => $tag)
                    @php $n = $count($tag); @endphp
                    <button type="button" class="tag-sticker" wire:key="tag-{{ $tag->slug() }}" wire:click="choose('tags', '{{ $tag->slug() }}')"
                            aria-pressed="{{ $chosen && $chosen->taxonomy() === 'tags' && $chosen->slug() === $tag->slug() ? 'true' : 'false' }}"
                            style="--size: {{ round(13 + min($n, $max) / $max * 10) }}px; --tone: var(--{{ ['coral', 'sun', 'mint', 'sky', 'lilac', 'rose', 'sand'][$i % 7] }}); --tilt: {{ ['-3deg', '2deg', '-1deg', '3deg', '-2deg', '1deg'][$i % 6] }}">
                        {{ $tag->title() }} <span>{{ $n }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        @if ($chosen)
            <div class="term-card">
                <p style="font-size: 15px">
                    <strong>{{ $chosen->taxonomy() === 'tags' ? '#' : '' }}{{ $chosen->title() }}</strong>
                    · {{ $chosen->taxonomy() === 'tags' ? 'tag' : 'category' }}
                    · used on {{ $count($chosen) }} {{ str('entry')->plural($count($chosen)) }}
                    @if ($chosen->attribute('uri')) · <a href="{{ url($chosen->attribute('uri')) }}" target="_blank" rel="noopener">see its page ↗</a> @endif
                </p>

                @if ($canManage)
                    <form wire:submit="renameTerm" class="term-card__row">
                        <div class="field" style="flex: 1 1 220px">
                            <label for="rename">Rename to</label>
                            <input id="rename" type="text" wire:model="rename" placeholder="{{ $chosen->title() }}" autocomplete="off">
                            @error('rename') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn--ink">Rename</button>
                    </form>

                    @if ($mergeChoices->isNotEmpty())
                        <form wire:submit="mergeTerm" class="term-card__row">
                            <div class="field" style="flex: 1 1 220px">
                                <label for="merge-into">Merge into…</label>
                                <select id="merge-into" wire:model="mergeInto">
                                    <option value="">Choose one</option>
                                    @foreach ($mergeChoices as $other)
                                        <option value="{{ $other->slug() }}">{{ $other->title() }}</option>
                                    @endforeach
                                </select>
                                @error('mergeInto') <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn">Merge</button>
                        </form>
                    @endif

                    <button type="button" class="btn btn--danger" style="align-self: flex-start" wire:click="removeTerm"
                            wire:confirm="Remove “{{ $chosen->title() }}” from every entry and delete it?">Remove</button>
                @else
                    <p class="field__hint">Editors and admins can rename, merge or remove these.</p>
                @endif
            </div>
        @endif

        <x-admin.folio side="right" />
    </section>
</div>
