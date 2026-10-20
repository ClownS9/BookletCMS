@php
    $visible = $history->take($shown);
    $statusWord = ['A' => 'added', 'M' => 'changed', 'D' => 'removed', '??' => 'new', 'R' => 'moved'];
@endphp
<div style="display: contents">
    {{-- Left page: switching it on, and what's waiting --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Settings</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">Version control</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">Every change to the book, committed to git on its own: who changed what, and when. Look back through it, and put the whole book back to any point.</p>
        </div>

        @error('git') <p class="field__error" role="alert">{{ $message }}</p> @enderror

        @if ($repo && ($merging || $conflicted !== []))
            <div class="vc-note vc-note--danger" role="alert">
                <p><strong>{{ $merging ? 'A git merge was started and not finished.' : 'Some files have unfinished merge marks.' }}</strong>
                    Lines starting <code>&lt;&lt;&lt;&lt;&lt;&lt;&lt;</code> and <code>&gt;&gt;&gt;&gt;&gt;&gt;&gt;</code> are git showing two versions side by side. Left in, they break pages (one in a stylesheet switches most of it off), so Booklet won’t commit until they’re gone.</p>
                @if ($conflicted !== [])
                    <ul class="vc-paths">@foreach (array_slice($conflicted, 0, 8) as $f) <li><code>{{ $f }}</code></li> @endforeach</ul>
                    <p class="field__hint">Open each one, keep the lines you want, and delete the three kinds of mark lines (<code>&lt;&lt;&lt;&lt;&lt;&lt;&lt;</code>, <code>=======</code>, <code>&gt;&gt;&gt;&gt;&gt;&gt;&gt;</code>). Or put the newest Booklet files back over them.</p>
                @endif
                @if ($merging)
                    <button type="button" class="btn btn--sm" wire:click="abortMerge"
                            wire:confirm="Undo the unfinished merge? Files go back to how they were before it started, including any half-fixed ones.">Undo the unfinished merge</button>
                @endif
            </div>
        @endif

        @if ($repo && $privateTracked !== [])
            <div class="vc-note vc-note--warn">
                <p><strong>Git is keeping {{ count($privateTracked) }} private {{ str('file')->plural(count($privateTracked)) }} from before</strong>, committed before the rules that leave them out. Booklet never adds them, but git keeps what it already has:</p>
                <ul class="vc-paths">@foreach (array_slice($privateTracked, 0, 6) as $f) <li><code>{{ $f }}</code></li> @endforeach
                    @if (count($privateTracked) > 6) <li class="muted">and {{ count($privateTracked) - 6 }} more</li> @endif</ul>
                <button type="button" class="btn btn--ink btn--sm" wire:click="untrackPrivate" wire:loading.attr="disabled" wire:target="untrackPrivate">Take them out of git</button>
                <p class="field__hint">They stay on this computer; git just stops keeping them. Older commits still have them: if the repository on GitHub is public and these include <code>.env</code> or form replies, change those passwords and ask for help clearing the old commits.</p>
            </div>
        @endif

        @if (! $files)
            <div class="vc-note vc-note--warn">
                <p><strong>Your content is kept in the database</strong>, so there are no files for git to keep a history of. Version control works when content is kept as files (<code>BOOKLET_DRIVER=file</code> in <code>.env</code>). Editions and Backups still look after the database.</p>
            </div>
        @elseif (! $installed)
            <div class="vc-note vc-note--warn">
                <p><strong>Git isn’t installed on this server.</strong> Install it (on a Mac: <code>xcode-select --install</code> or <code>brew install git</code>), or set <code>BOOKLET_GIT_BINARY</code> to where it is.</p>
            </div>
        @elseif (! $repo)
            <div class="vc-start">
                <p><strong>There’s no history here yet.</strong> Booklet will start one in <code>{{ $root }}</code> and commit only the book’s own folders:</p>
                <ul class="vc-paths">@foreach ($wanted as $p) <li><code>{{ $p }}</code></li> @endforeach</ul>
                <p class="field__hint">Your code isn’t touched or committed. Nothing is sent anywhere unless you switch that on.</p>
                <button type="button" class="btn btn--ink btn--lg" wire:click="startHistory" wire:loading.attr="disabled" wire:target="startHistory">
                    <span wire:loading.remove wire:target="startHistory">Start the history</span>
                    <span wire:loading wire:target="startHistory">Starting…</span>
                </button>
            </div>
        @else
            <div class="setting-rows">
                <div class="setting-row">
                    <span>
                        <span id="vc-on" style="display: block; font-size: 15px; font-weight: 500">Commit every change</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">A couple of minutes after things go quiet, so a burst of saves is one commit.@if ($fixed['on']) Set in .env (BOOKLET_GIT).@endif</span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-labelledby="vc-on" aria-checked="{{ $settings['on'] ? 'true' : 'false' }}" wire:click="toggle('on')" @disabled($fixed['on'])></button>
                </div>
                <div class="setting-row">
                    <span>
                        <span id="vc-push" style="display: block; font-size: 15px; font-weight: 500">Send commits to {{ $connected ? ($remoteLabel ?? $remote) : 'GitHub' }}</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">
                            @if ($connected)
                                After each commit, it goes there too, so there’s a copy off this computer.
                            @else
                                Connect to GitHub below first. It takes three steps, no commands.
                            @endif
                            @if ($fixed['push']) Set in .env (BOOKLET_GIT_PUSH).@endif
                        </span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-labelledby="vc-push" aria-checked="{{ $settings['push'] ? 'true' : 'false' }}" wire:click="toggle('push')"
                            @disabled($fixed['push'] || (! $settings['push'] && ! in_array($remote, $remotes, true)))></button>
                </div>
                <div class="setting-row">
                    <span>
                        <span id="vc-media" style="display: block; font-size: 15px; font-weight: 500">Keep pictures and files too</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">Everything in Media, not just its descriptions. Makes the history much bigger.</span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-labelledby="vc-media" aria-checked="{{ $settings['media'] ? 'true' : 'false' }}" wire:click="toggle('media')"></button>
                </div>
                <div class="setting-row">
                    <span>
                        <span id="vc-everything" style="display: block; font-size: 15px; font-weight: 500">Commit everything, not just content</span>
                        <span style="display: block; font-size: 13px; color: var(--faint)">Code, the README, screenshots: the whole project, apart from what <code>.gitignore</code> leaves out. Never <code>.env</code>, the database file, <code>vendor</code>, <code>node_modules</code>, logs or backups. Files are committed once they’ve been left alone for a couple of minutes.</span>
                    </span>
                    <button type="button" class="switch" role="switch" aria-labelledby="vc-everything" aria-checked="{{ $settings['everything'] ? 'true' : 'false' }}" wire:click="toggle('everything')"
                            wire:confirm="{{ $settings['everything'] ? 'Go back to committing the book’s content only?' : 'Commit the whole project, code included, on its own? Most people commit code themselves when it’s finished. Secrets, the database, vendor and logs are always left out.' }}"></button>
                </div>
            </div>

            @include('livewire.admin.partials.version-control-github')

            <div class="vc-waiting">
                @if ($pending === [])
                    <p><span class="vc-dot vc-dot--ok" aria-hidden="true"></span> Everything is committed.</p>
                @else
                    <p><span class="vc-dot" aria-hidden="true"></span> <strong>{{ count($pending) }} changed {{ str('file')->plural(count($pending)) }}</strong> waiting{{ $settings['on'] ? ' for a quiet moment' : '' }}:</p>
                    <ul>
                        @foreach (collect($pending)->take(6) as [$st, $path])
                            <li title="{{ $path }}"><span class="vc-badge vc-badge--{{ ['??' => 'A', 'A' => 'A', 'D' => 'D'][$st] ?? 'M' }}">{{ $statusWord[$st] ?? 'changed' }}</span> {{ \App\Support\VersionControl::label($path) }}</li>
                        @endforeach
                        @if (count($pending) > 6) <li class="muted">and {{ count($pending) - 6 }} more</li> @endif
                    </ul>
                @endif
                <form wire:submit="commitNow" class="vc-commit">
                    <label for="vc-message" class="sr-only">What changed (optional)</label>
                    <input id="vc-message" type="text" class="input" wire:model="message" maxlength="100" placeholder="What changed (optional)">
                    <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="commitNow">Commit now</button>
                </form>
                @error('message') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            @if (! empty($status['at']))
                <dl class="vc-status">
                    @if (! empty($status['at']))
                        <div><dt>Last commit</dt><dd>{{ \Illuminate\Support\Carbon::parse($status['at'])->diffForHumans() }} · <code>{{ $status['hash'] ?? '' }}</code></dd></div>
                    @endif
                </dl>
            @endif

            <div class="backup-notes">
                <p><strong>In the history:</strong> @if ($settings['everything']) the whole project (code too), apart from what’s ignored and never committed. @else posts, pages, categories and tags, menus and page order, settings, blueprints and forms{{ $settings['media'] ? ', and Media' : ', and what’s written about each picture' }}. @endif</p>
                <p><strong>Not in it:</strong> people, comments, form replies and visit counts, which live in the database. <a href="{{ route('admin.backups') }}" wire:navigate>Backups</a> keep those.</p>
                <p>For a single post, its <strong>History</strong> (editions) is quicker. This is for the whole book at once.</p>
            </div>
        @endif

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the history, as stitches in the binding --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>
        <div class="ribbon-clear">
            <p class="eyebrow">The binding</p>
            <h2 class="heading" style="font-size: 28px; margin-top: 6px">Every change, newest first</h2>
        </div>

        @if (! $repo || $visible->isEmpty())
            <p class="empty-note">{{ $repo ? 'No commits of the book yet. The first comes with the next change.' : 'Once the history starts, every change shows up here.' }}</p>
        @else
            <ol class="vc-log" role="list">
                @foreach ($visible as $i => $c)
                    <li @class(['vc-commit-row', 'vc-commit-row--open' => $open === $c['hash']]) wire:key="c-{{ $c['hash'] }}">
                        <span class="vc-stitch" aria-hidden="true"></span>
                        <button type="button" class="vc-commit-row__head" wire:click="openCommit('{{ $c['hash'] }}')" aria-expanded="{{ $open === $c['hash'] ? 'true' : 'false' }}">
                            <span class="vc-commit-row__subject">{{ $c['subject'] }}</span>
                            <span class="vc-commit-row__meta">
                                {{ $c['author'] }} · <time datetime="{{ $c['at']->toIso8601String() }}" title="{{ $c['at']->format('j M Y, H:i') }}">{{ $c['at']->diffForHumans() }}</time>
                                @if ($c['files']) · {{ $c['files'] }} {{ str('file')->plural($c['files']) }} @endif
                                · <code>{{ $c['short'] }}</code>
                            </span>
                        </button>

                        @if ($open === $c['hash'])
                            <div class="vc-commit-row__body">
                                @if ($c['body'] !== '')
                                    <p class="vc-commit-row__lines">{!! nl2br(e($c['body'])) !!}</p>
                                @endif
                                @if ($openFiles !== [])
                                    <ul class="vc-files">
                                        @foreach (array_slice($openFiles, 0, 12) as [$st, $path, $label])
                                            <li title="{{ $path }}"><span class="vc-badge vc-badge--{{ $st }}">{{ $statusWord[$st] ?? $st }}</span> <span>{{ $label }}</span></li>
                                        @endforeach
                                        @if (count($openFiles) > 12) <li class="muted">and {{ count($openFiles) - 12 }} more</li> @endif
                                    </ul>
                                @endif

                                @if ($i > 0)
                                    @if ($restoring === $c['hash'])
                                        <div class="vc-restore" role="alertdialog" aria-labelledby="vc-restore-{{ $c['short'] }}">
                                            <p id="vc-restore-{{ $c['short'] }}"><strong>Put the whole book back to how it was {{ $c['at']->format('j M Y, H:i') }}?</strong> Posts, pages, settings and blueprints go back; anything made since is taken out. People, comments and code aren’t touched. It’s saved as a new commit, so you can undo it the same way.</p>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap">
                                                <button type="button" class="btn btn--ink btn--sm" wire:click="restore" wire:loading.attr="disabled" wire:target="restore">Yes, put it back</button>
                                                <button type="button" class="btn btn--sm" wire:click="$set('restoring', null)">Cancel</button>
                                            </div>
                                        </div>
                                    @else
                                        <button type="button" class="btn btn--sm" wire:click="askRestore('{{ $c['hash'] }}')">Put the book back to here</button>
                                    @endif
                                @else
                                    <p class="field__hint" style="margin: 0">This is how the book is now.</p>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if ($history->count() > $shown)
                <button type="button" class="btn btn--dashed btn--sm" style="align-self: flex-start" wire:click="more">Show older</button>
            @endif
        @endif

        <x-admin.folio side="right" />
    </section>
</div>
