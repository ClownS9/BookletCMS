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
        @if ($shared !== [])
            {{-- Sections used on other pages too: the same one, here --}}
            <h3 class="d-group">Shared</h3>
            <ul class="d-kinds">
                @foreach ($shared as $one)
                    <li class="d-kinds__shared">
                        <button type="button" class="d-kind" data-do="add" data-payload="{{ $j('shared:'.$one['id']) }}">
                            <span class="d-kind__icon d-kind__icon--{{ $one['type'] }} d-kind__icon--shared" aria-hidden="true"></span>
                            <span><b>{{ $one['name'] }}</b><small>{{ $one['kind'] }} · {{ $one['uses'] === 0 ? 'on no pages yet' : 'on '.$one['uses'].' '.str('page')->plural($one['uses']) }}</small></span>
                        </button>
                        @if ($one['uses'] === 0)
                            <button type="button" class="d-icon d-danger" data-do="forget-shared" data-payload="{{ $one['id'] }}" data-confirm="Forget the shared section “{{ $one['name'] }}”? No page uses it." aria-label="Forget “{{ $one['name'] }}”">×</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @foreach (collect($types)->groupBy('group', true) as $group => $kinds)
            <h3 class="d-group">{{ $group }}</h3>
            <ul class="d-kinds">
                @foreach ($kinds as $type => $kind)
                    @continue (! empty($kind['required']) || (! empty($kind['once']) && collect($sections)->contains('type', $type)))
                    <li>
                        <button type="button" class="d-kind" data-do="add" data-payload="{{ $j($type) }}">
                            <span class="d-kind__icon d-kind__icon--{{ $type }}" aria-hidden="true"></span>
                            <span><b>{{ $kind['name'] }}</b><small>{{ $kind['about'] }}</small></span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endforeach

    @elseif ($state['tab'] === 'page' && $isBlog)
        {{-- The blog page: it lives where the blog does, and is never deleted --}}
        <p class="d-note">The blog page lives at <code>{{ $address }}</code>. Its posts are always on it; everything around them is yours to arrange.</p>
        @include('builder.checks')
        @include('builder.status')
        @include('builder.history')
        @if ($changed)
            <div class="d-more">
                <button type="button" class="d-link" data-do="discard" data-confirm="Throw away the changes since you last published?">Go back to what readers see</button>
            </div>
        @endif

    @elseif ($state['tab'] === 'page')
        @include('builder.checks')
        @include('builder.status')
        @include('builder.history')
        @include('builder.proofs')

        {{-- The page's (or post's) own details: they're its own, in the book too --}}
        @php
            $slug = (string) $entry?->slug();
            $base = rtrim(\Illuminate\Support\Str::beforeLast((string) $entry?->uri(), '/'.$slug), '/');
        @endphp
        <form class="d-form" data-form="details">
            <label class="d-field">
                <span>Name</span>
                <input type="text" name="name" value="{{ $entry?->title() }}" maxlength="120" required>
                <small>Its title in the book, in menus, the browser tab and search results.</small>
                @if (! empty($errors['name'])) <em class="d-error">{{ $errors['name'] }}</em> @endif
            </label>
            <label class="d-field">
                <span>Address</span>
                <span class="d-address"><i>{{ preg_replace('#^https?://#', '', rtrim(url('/'), '/')).$base }}/</i><input type="text" name="address" value="{{ $slug }}" maxlength="160" autocapitalize="off" spellcheck="false" required></span>
                @if (! empty($errors['address'])) <em class="d-error">{{ $errors['address'] }}</em> @endif
            </label>
            <label class="d-field">
                <span>Description <i>(for search results and shared links)</i></span>
                <textarea name="description" rows="3" maxlength="300">{{ $entry?->get('meta_description') }}</textarea>
            </label>
            <button type="submit" class="d-btn">Save</button>
        </form>

        @if ($canHome)
            <label class="d-check"><input type="checkbox" data-home @checked($home)> Use it as the home page</label>
        @endif

        <div class="d-more">
            @if ($entry && $entry->published() && $address !== '/')
                <p class="d-note">In another post or page: <code>[[builder: {{ trim($address, '/') }}]]</code></p>
            @endif
            @if ($changed)
                <button type="button" class="d-link" data-do="discard" data-confirm="Throw away the changes since you last published?">Go back to what’s on the site</button>
            @endif
            @if ($entry)
                <a class="d-link" href="{{ route('admin.entries.edit', ['collection' => $entry->collectionHandle(), 'entry' => $entry->id()]) }}">Open it in the book</a>
                <button type="button" class="d-link d-danger" data-do="stop" data-confirm="Draw “{{ $entry->title() }}” as before, with its own words? What you built here is let go.">Stop building it</button>
            @endif
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
                            <b>{{ $types[$s['type']]['name'] ?? $s['type'] }}@if (isset($s['shared'])) <span class="d-tag">shared</span>@endif</b>
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
        <p class="d-note">{{ $kind['fields'] === [] ? 'It draws itself: there’s nothing to fill in. How it looks is below.' : 'Click words on the page to type into them. Everything else is here.' }}</p>

        @if ($sharedNow)
            {{-- Used on other pages too --}}
            <div class="d-shared">
                <p class="d-note"><b>Shared: “{{ $sharedNow['name'] }}”</b> · on {{ $sharedNow['uses'] }} {{ str('page')->plural($sharedNow['uses']) }}.
                    {{ $sharedNow['uses'] > 1 ? 'Changes here show on all of them when this page is published.' : 'Add it to other pages from Add a section › Shared; changes then show on all of them.' }}</p>
                <button type="button" class="d-link" data-do="unshare" data-payload="{{ $j($sid) }}">Make it this page’s own</button>
            </div>
        @elseif ($canShare)
            <details class="d-share" @if (! empty($errors['share'])) open @endif>
                <summary>Use it on other pages too</summary>
                <form class="d-form" data-form="share">
                    <input type="hidden" name="id" value="{{ $sid }}">
                    <label class="d-field">
                        <span>Its name (to find it by)</span>
                        <input type="text" name="name" maxlength="80" placeholder="Opening hours, Our call to action…" value="{{ request()->input('payload.name') }}">
                        @if (! empty($errors['share'])) <em class="d-error">{{ $errors['share'] }}</em> @endif
                        <small>Then add it to any page from Add a section › Shared. Change it once, and it changes everywhere.</small>
                    </label>
                    <button type="submit" class="d-btn">Share it</button>
                </form>
            </details>
        @endif

        @foreach ($kind['fields'] as $name => $spec)
            @include('builder.field', ['spec' => $spec, 'path' => $name, 'value' => $selected['fields'][$name] ?? null])
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
