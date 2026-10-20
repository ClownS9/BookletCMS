<div style="display: contents">
    {{-- Left page: how it works, and the upload --}}
    <section class="page page--left page--stack" style="flex-basis: 400px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Settings</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">Move in from WordPress</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">Bring your posts, pages, categories, tags, comments and pictures across, with the old addresses forwarding to the new ones so shared links keep working.</p>
        </div>

        <ol class="import-steps">
            <li><strong>In WordPress</strong>, go to <em>Tools › Export</em>, choose <em>All content</em> and download the export file.</li>
            <li><strong>Upload it here.</strong> You’ll see what’s inside before anything happens.</li>
            <li><strong>Bring it in.</strong> Your WordPress site isn’t changed at all.</li>
        </ol>

        <form method="POST" action="{{ route('admin.import.upload') }}" enctype="multipart/form-data" class="backup-upload">
            @csrf
            <label for="export-file" class="field__label">The export file (.xml)</label>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                <input id="export-file" type="file" name="export" accept=".xml,text/xml,application/xml" required>
                <button type="submit" class="btn btn--sm">Upload and look inside</button>
            </div>
            @error('export') <p class="field__error">{{ $message }}</p> @enderror
            <p class="field__hint">Up to {{ \App\Support\WordPressImport::MAX_BYTES / 1048576 }} MB. A very big site can be exported a year at a time.</p>
        </form>

        <div class="backup-notes">
            <p><strong>Comes across:</strong> posts and pages (and their drafts), dates, categories and tags, featured pictures and the pictures in posts, alt text, Yoast or Rank Math search text, approved comments with their replies, and page order.</p>
            <p><strong>Stays behind:</strong> themes, plugins and their blocks (galleries, forms, shop items), menus, and anything only a plugin knows how to show. Password-protected posts come in as drafts.</p>
            <p>Run it again later and anything already brought in is skipped.</p>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: what's inside, the choices, and how it went --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($result)
            <div class="ribbon-clear">
                <p class="eyebrow">Done</p>
                <h2 class="heading" style="margin-top: 6px">Moved in</h2>
            </div>
            @php $r = $result['report']; @endphp
            <dl class="import-counts">
                @foreach (['posts' => 'Posts', 'pages' => 'Pages', 'drafts' => 'Drafts', 'pictures' => 'Pictures', 'comments' => 'Comments', 'redirects' => 'Redirects', 'categories' => 'New categories', 'tags' => 'New tags', 'people' => 'New writers', 'already' => 'Already here'] as $key => $label)
                    @if (($r[$key] ?? 0) > 0 || in_array($key, ['posts', 'pages'], true))
                        <div><dt>{{ $label }}</dt><dd>{{ number_format($r[$key] ?? 0) }}</dd></div>
                    @endif
                @endforeach
            </dl>
            @if ($result['notes'])
                <div>
                    <h3 class="eyebrow section-label">Worth knowing</h3>
                    <ul class="import-notes">
                        @foreach ($result['notes'] as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                    @if ($result['more_notes']) <p class="field__hint">…and {{ $result['more_notes'] }} more like these.</p> @endif
                </div>
            @endif
            <div style="display: flex; gap: 10px; flex-wrap: wrap">
                <a href="{{ route('admin.entries', 'posts') }}" class="btn btn--ink" wire:navigate>See the posts</a>
                <a href="{{ route('admin.entries', 'pages') }}" class="btn" wire:navigate>Pages</a>
                <a href="{{ route('admin.redirects') }}" class="btn btn--quiet" wire:navigate>Redirects</a>
            </div>
        @elseif ($problem)
            <div class="ribbon-clear">
                <h2 class="heading">That didn’t open</h2>
                <p class="field__error" style="margin-top: 8px">{{ $problem }}</p>
                <button type="button" class="btn btn--sm" style="margin-top: 12px" wire:click="cancel">Start again</button>
            </div>
        @elseif ($summary)
            <div class="ribbon-clear">
                <p class="eyebrow">Inside the export</p>
                <h2 class="heading" style="margin-top: 6px">{{ $summary['site']['title'] ?: 'Your WordPress site' }}</h2>
                <p class="field__hint" style="margin-top: 4px">{{ preg_replace('#^https?://#', '', $summary['site']['base']) }}</p>
            </div>

            <dl class="import-counts">
                <div><dt>Posts</dt><dd>{{ number_format($summary['posts']) }}</dd></div>
                <div><dt>Pages</dt><dd>{{ number_format($summary['pages']) }}</dd></div>
                <div><dt>Drafts</dt><dd>{{ number_format($summary['drafts']) }}</dd></div>
                <div><dt>Pictures</dt><dd>{{ number_format($summary['pictures']) }}</dd></div>
                <div><dt>Comments</dt><dd>{{ number_format($summary['comments']) }}</dd></div>
                <div><dt>Categories · tags</dt><dd>{{ $summary['categories'] }} · {{ $summary['tags'] }}</dd></div>
            </dl>
            @if ($summary['scheduled'] || $summary['protected'] || $summary['already'] || $summary['other'])
                <ul class="import-notes">
                    @if ($summary['scheduled']) <li>{{ $summary['scheduled'] }} scheduled {{ str('post')->plural($summary['scheduled']) }} will stay scheduled.</li> @endif
                    @if ($summary['protected']) <li>{{ $summary['protected'] }} password-protected {{ str('entry')->plural($summary['protected']) }} will come in as {{ str('draft')->plural($summary['protected']) }}.</li> @endif
                    @if ($summary['already']) <li>{{ $summary['already'] }} {{ str('entry')->plural($summary['already']) }} came across before and will be skipped.</li> @endif
                    @foreach ($summary['other'] as $type => $n)
                        <li>{{ $n }} “{{ $type }}” {{ str('item')->plural($n) }} from a plugin can’t come across.</li>
                    @endforeach
                </ul>
            @endif

            <fieldset class="import-opts" @disabled(false) wire:loading.attr="disabled" wire:target="bringIn">
                <legend class="eyebrow section-label">How</legend>
                <label class="check"><input type="checkbox" wire:model="pictures"> Bring the pictures over <span class="muted">(otherwise they stay on the old site)</span></label>
                <label class="check"><input type="checkbox" wire:model="drafts"> Drafts too</label>
                <label class="check"><input type="checkbox" wire:model="comments"> Comments</label>
                <label class="check"><input type="checkbox" wire:model="redirects"> Forward the old addresses here</label>
                <div class="field" style="margin-top: 6px">
                    <label for="import-authors">Who wrote them</label>
                    <select id="import-authors" wire:model="authors">
                        <option value="match">Match people by email (the rest are yours)</option>
                        <option value="create">Match by email, and add the others as writers</option>
                        <option value="me">Everything is mine</option>
                    </select>
                    @if ($summary['authors'])
                        <p class="field__hint">In the export: {{ collect($summary['authors'])->map(fn ($a) => $a['name'])->implode(', ') }}.</p>
                    @endif
                </div>
            </fieldset>
            @error('file') <p class="field__error">{{ $message }}</p> @enderror

            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap">
                <button type="button" class="btn btn--ink btn--lg" wire:click="bringIn" wire:loading.attr="disabled" wire:target="bringIn">
                    <span wire:loading.remove wire:target="bringIn">Bring it in</span>
                    <span wire:loading wire:target="bringIn">Moving in… (pictures take a while)</span>
                </button>
                <button type="button" class="link-button" wire:click="cancel" wire:loading.remove wire:target="bringIn">Cancel</button>
            </div>
            <p class="field__hint">A big site can take several minutes. Keep this page open until it’s done. (For very big ones, whoever runs the server can use <code>php artisan booklet:import-wordpress</code>.)</p>
        @else
            <div class="ribbon-clear import-empty">
                <span class="sticker sticker--sand" style="--tilt: -3deg">Moving boxes</span>
                <p class="serif" style="font-size: 22px; margin: 18px 0 0">Nothing to look at yet.</p>
                <p class="field__hint" style="margin-top: 6px">Upload a WordPress export on the left and its contents show up here.</p>
            </div>
        @endif

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
