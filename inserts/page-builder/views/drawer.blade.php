{{--
    The editor's drawer, on the page (drawn inside its own sealed-off layer,
    so the site's theme can't touch it). Buttons say what to do with
    data-do="action" data-payload="…"; boxes change a field with data-set.
--}}
@php
    $sid = $selected['id'] ?? null;
    $j = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$where, $words] = $status;
@endphp
<div class="d" @if ($sid) data-section="{{ $sid }}" @endif data-view="{{ $state['picking'] ? 'picking' : ($state['adding'] ? 'adding' : $state['tab']) }}">
    <div class="d-tabs" role="tablist" aria-label="Editing">
        <button type="button" role="tab" data-do="tab" data-payload='"section"' aria-selected="{{ $state['tab'] === 'section' && ! $state['adding'] && ! $state['picking'] ? 'true' : 'false' }}">Section</button>
        <button type="button" role="tab" data-do="tab" data-payload='"outline"' aria-selected="{{ $state['tab'] === 'outline' && ! $state['adding'] && ! $state['picking'] ? 'true' : 'false' }}">All sections</button>
        <button type="button" role="tab" data-do="tab" data-payload='"page"' aria-selected="{{ $state['tab'] === 'page' && ! $state['adding'] && ! $state['picking'] ? 'true' : 'false' }}">Page</button>
    </div>

    <div class="d-body">
    @if ($state['picking'])
        {{-- Choosing pictures from Media --}}
        @php
            $target = collect($sections)->firstWhere('id', $state['picking']['id']);
            $many = $target && ($builder->field($target['type'], $state['picking']['field'])['kind'] ?? '') === 'pictures';
            $chosen = ! $target ? [] : ($state['picking']['field'] === 'look.picture' ? [$target['look']['picture']] : (array) data_get($target['fields'], $state['picking']['field'], []));
        @endphp
        <div class="d-row">
            <h2 class="d-h">{{ $many ? 'Press pictures to add or take them out' : 'Choose a picture' }}</h2>
            <button type="button" class="d-btn" data-do="unpick">{{ $many ? 'Done' : 'Cancel' }}</button>
        </div>
        @if ($pictures === [])
            <p class="d-note">There are no pictures in Media yet. <a href="{{ route('admin.media') }}">Add some in the book →</a></p>
        @else
            <ul class="d-pictures">
                @foreach ($pictures as $path => $pic)
                    @php $in = in_array($path, $chosen, true); @endphp
                    <li>
                        <button type="button" data-do="choose" data-payload="{{ $j($path) }}" aria-pressed="{{ $in ? 'true' : 'false' }}" aria-label="{{ $pic['alt'] ?: basename($path) }}">
                            <img src="{{ $pic['thumb'] }}" alt="" loading="lazy">
                            @if ($in && $many) <span class="d-pictures__n" aria-hidden="true">{{ array_search($path, $chosen, true) + 1 }}</span> @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

    @elseif ($state['adding'])
        {{-- Adding a section --}}
        <div class="d-row">
            <h2 class="d-h">Add a section{{ $selected ? ' after “'.($types[$selected['type']]['name'] ?? '').'”' : '' }}</h2>
            <button type="button" class="d-btn" data-do="adding" data-payload="false">Cancel</button>
        </div>
        @foreach (collect($types)->groupBy('group', true) as $group => $kinds)
            <h3 class="d-group">{{ $group }}</h3>
            <ul class="d-kinds">
                @foreach ($kinds as $type => $kind)
                    <li>
                        <button type="button" class="d-kind" data-do="add" data-payload="{{ $j($type) }}">
                            <span class="d-kind__icon d-kind__icon--{{ $type }}" aria-hidden="true"></span>
                            <span><b>{{ $kind['name'] }}</b><small>{{ $kind['about'] }}</small></span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endforeach

    @elseif ($state['tab'] === 'page')
        {{-- The page's own details --}}
        <form class="d-form" data-form="details">
            <label class="d-field">
                <span>Name</span>
                <input type="text" name="name" value="{{ $page->field('title') }}" maxlength="120" required>
                <small>The title in the browser tab and in search results.</small>
                @if (! empty($errors['name'])) <em class="d-error">{{ $errors['name'] }}</em> @endif
            </label>
            <label class="d-field">
                <span>Address</span>
                <span class="d-address"><i>{{ preg_replace('#^https?://#', '', rtrim(url('/'), '/')) }}/</i><input type="text" name="address" value="{{ $page->slug }}" maxlength="160" autocapitalize="off" spellcheck="false" required></span>
                @if (! empty($errors['address'])) <em class="d-error">{{ $errors['address'] }}</em> @endif
            </label>
            <label class="d-field">
                <span>Description <i>(for search results and shared links)</i></span>
                <textarea name="description" rows="3" maxlength="300">{{ $page->field('description') }}</textarea>
            </label>
            <button type="submit" class="d-btn">Save</button>
        </form>

        @if ($canHome)
            <label class="d-check"><input type="checkbox" data-home @checked($page->field('home'))> Use it as the home page</label>
        @endif

        @php $replaces = (array) $page->field('replaces', []); @endphp
        @if ($replaces !== [])
            <div class="d-replace">
                @if (! empty($replaces['done']))
                    <p class="d-note">{{ ! empty($replaces['home']) ? 'Your home page, in place of “'.$replaces['title'].'”.' : 'In place of your page “'.$replaces['title'].'”, which is kept as a draft.' }}</p>
                    <button type="button" class="d-btn" data-do="restore" data-confirm="Put “{{ $replaces['title'] }}” back{{ ! empty($replaces['home']) ? ' as your home page' : ' at /'.$replaces['address'] }}?{{ ! empty($replaces['home']) ? '' : ' This built page goes back to being a draft.' }}">Put the old page back</button>
                @else
                    <p class="d-note">Built from “{{ $replaces['title'] }}”. {{ ! empty($replaces['home']) ? 'When you publish, it becomes your home page.' : 'When you publish, it takes its place at /'.$replaces['address'].' (the old page is kept as a draft).' }}</p>
                @endif
            </div>
        @endif

        <div class="d-more">
            <p class="d-note">In a post or page: <code>[[builder: {{ $page->slug }}]]</code></p>
            @if ($where === 'changed')
                <button type="button" class="d-link" data-do="discard" data-confirm="Throw away the changes since you last published?">Go back to what’s on the site</button>
            @endif
            @if ($where !== 'draft')
                <button type="button" class="d-link" data-do="unpublish" data-confirm="Take “{{ $page->field('title') }}” off the site? It stays here as a draft.">Take it off the site</button>
            @endif
            <button type="button" class="d-link d-danger" data-do="delete" data-payload="{{ $page->id }}" data-confirm="Delete “{{ $page->field('title') }}”? This can’t be undone.">Delete this page</button>
        </div>

    @elseif ($state['tab'] === 'outline')
        {{-- Every section, top to bottom: drag to reorder --}}
        @if ($sections === [])
            <p class="d-note">Nothing on this page yet.</p>
        @else
            <p class="d-note">Drag to change the order, or use ↑ ↓.</p>
            <ol class="d-outline">
                @foreach ($sections as $i => $s)
                    @php $summary = $builder->summary($s); @endphp
                    <li data-id="{{ $s['id'] }}" data-at="{{ $i }}" draggable="true" @class(['is-selected' => $s['id'] === $sid])>
                        <span class="d-grip" aria-hidden="true">⋮⋮</span>
                        <button type="button" class="d-outline__pick" data-do="select" data-payload="{{ $j($s['id']) }}">
                            <b>{{ $types[$s['type']]['name'] ?? $s['type'] }}</b>
                            @if ($summary !== '') <small>{{ $summary }}</small> @endif
                        </button>
                        <button type="button" class="d-icon" data-do="move" data-payload="{{ $j(['id' => $s['id'], 'step' => -1]) }}" @disabled($loop->first) aria-label="Move up">↑</button>
                        <button type="button" class="d-icon" data-do="move" data-payload="{{ $j(['id' => $s['id'], 'step' => 1]) }}" @disabled($loop->last) aria-label="Move down">↓</button>
                    </li>
                @endforeach
            </ol>
        @endif
        <button type="button" class="d-btn" data-do="adding" data-payload="{{ $j($sections === [] ? true : end($sections)['id']) }}">+ Add a section at the end</button>

    @elseif ($selected)
        {{-- The chosen section --}}
        @php $kind = $types[$selected['type']]; @endphp
        <div class="d-row">
            <h2 class="d-h">{{ $kind['name'] }}</h2>
        </div>
        <p class="d-note">Click words on the page to type into them. Everything else is here.</p>

        @foreach ($kind['fields'] as $name => $spec)
            @include('insert-page-builder::field', ['spec' => $spec, 'path' => $name, 'value' => $selected['fields'][$name] ?? null])
        @endforeach

        <details class="d-look" open>
            <summary>How it looks</summary>
            @foreach ($looks as $key => $look)
                <div class="d-look__row" role="group" aria-label="{{ $look['label'] }}">
                    <span class="d-label">{{ $look['label'] }}</span>
                    <span class="d-choices">
                        @foreach ($look['options'] as $value => $words)
                            @if ($key === 'background' && $value === 'picture' && $selected['look']['picture'] === '')
                                <button type="button" class="d-pill" aria-pressed="false" data-do="pick" data-payload="{{ $j(['id' => $sid, 'field' => 'look.picture']) }}">{{ $words }}</button>
                            @else
                                <button type="button" class="d-pill" aria-pressed="{{ $selected['look'][$key] === $value ? 'true' : 'false' }}"
                                        data-do="look" data-payload="{{ $j(['id' => $sid, 'key' => $key, 'value' => $value]) }}">{{ $words }}</button>
                            @endif
                        @endforeach
                    </span>
                </div>
            @endforeach
            @if ($selected['look']['background'] === 'picture')
                <button type="button" class="d-link" data-do="pick" data-payload="{{ $j(['id' => $sid, 'field' => 'look.picture']) }}">Change the background picture</button>
            @endif
        </details>

    @else
        <p class="d-note d-hint">Click a section on the page to change it, or <button type="button" class="d-link" data-do="adding" data-payload="true">add one</button>.</p>
    @endif
    </div>
</div>
