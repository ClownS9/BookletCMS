{{--
    "Share it": how to give a theme or an insert to other Booklet sites, step by
    step, with the names to use already filled in. Expects $kind ('theme' or
    'insert'), $key, $name, $version, $about, and $code (whether it's code).
--}}
@php
    $repo = \App\Support\Sharing::repository($key);
    $owner = \App\Support\Sharing::githubOwner();
    $topic = \App\Support\Sharing::TOPICS[$kind];
    $manifest = $kind === 'theme' ? 'theme.json' : 'insert.php';
    $where = $kind === 'theme' ? 'Settings › The cover › + Add a theme' : 'Settings › Inserts › + Add';
    // What's at the top of its folder, so they know what they're looking at once it's unzipped.
    $folder = $kind === 'theme' ? \App\Themes\ThemeManager::find($key)?->path : app(\App\Inserts\InsertManager::class)->find($key)?->path;
    $top = collect($folder ? (scandir($folder) ?: []) : [])->reject(fn ($f) => str_starts_with($f, '.'))->values();
    $files = $top->reject(fn ($f) => is_dir($folder.'/'.$f))->sortBy(fn ($f) => $f === $manifest ? 0 : 1)->values();
    $dirs = $top->filter(fn ($f) => is_dir($folder.'/'.$f))->values();
    $inside = $files->implode(', ').($dirs->isNotEmpty() ? ($files->isNotEmpty() ? ', and ' : '').($dirs->count() > 1 ? 'the folders ' : 'the folder ').$dirs->implode(' and ') : '');
    $inside = $inside !== '' ? $inside : $manifest;
    // Added from GitHub already (someone else's, often): share where it came from, not a copy of your own.
    $from = isset($from) && ($from['type'] ?? null) === 'github' ? (string) $from['repo'] : null;
@endphp
<details class="share-guide" wire:key="share-{{ $kind }}-{{ $key }}" wire:ignore.self
         x-data="{ copied: null, copy(text, id) { navigator.clipboard?.writeText(text).then(() => { this.copied = id; setTimeout(() => this.copied = null, 1600) }) } }">
    <summary class="share-guide__summary">
        <span><b>Share {{ $name }}</b> <span class="field__hint">with other Booklet sites</span></span>
        <span class="share-guide__chevron" aria-hidden="true">›</span>
    </summary>

    <div class="share-guide__body">
        @if ($from)
            <p style="margin: 0">This came from GitHub, so it’s already there to share: others add it in <b>{{ $where }}</b> with this name, and get its updates from whoever made it.</p>
            <span class="share-guide__value"><code>{{ $from }}</code>
                <button type="button" class="link-button" x-on:click="copy(@js($from), 'from')"><span x-text="copied === 'from' ? 'Copied' : 'Copy'">Copy</span></button>
                <a href="https://github.com/{{ $from }}" target="_blank" rel="noopener" class="field__hint" style="margin: 0">Open it on GitHub <span aria-hidden="true">↗</span></a></span>
        @else
        <p style="margin: 0">There are two ways. Sending the .zip is quickest. Putting it on GitHub lets anyone add it by name and get your updates.</p>

        <section class="share-guide__way">
            <h4 class="share-guide__title">The quick way: send the .zip</h4>
            <p style="margin: 0">Booklet packs it and checks it the same way another site will, so what you send is what they can add.</p>
            <a href="{{ route('admin.share.download', [$kind, $key]) }}" class="btn btn--sm" style="align-self: flex-start" download>Download booklet-{{ $key }}-{{ $version }}.zip</a>
            <p class="field__hint" style="margin: 0">Send it by email or chat. They add it in <b>{{ $where }}</b>, under <b>From a .zip on your computer</b>.</p>
        </section>

        <section class="share-guide__way">
            <h4 class="share-guide__title">The GitHub way: add by name, with updates</h4>
            <ol class="share-guide__steps">
                <li>
                    <b>Download the .zip</b> (the button above) and unzip it. Inside: {{ $inside }}.
                </li>
                <li>
                    <b>Make a repository on GitHub.</b> This opens GitHub with the name filled in. Keep it <b>Public</b>, leave the README, .gitignore and licence boxes unticked, and press <b>Create repository</b>.
                    <a href="{{ \App\Support\Sharing::newRepositoryUrl($key, $about) }}" target="_blank" rel="noopener" class="btn btn--sm share-guide__go">Open GitHub: new repository <span aria-hidden="true">↗</span></a>
                    <span class="share-guide__value"><span class="field__hint">Its name:</span> <code>{{ $repo }}</code>
                        <button type="button" class="link-button" x-on:click="copy(@js($repo), 'repo')"><span x-text="copied === 'repo' ? 'Copied' : 'Copy'">Copy</span></button></span>
                </li>
                <li>
                    <b>Upload the files.</b> On the new, empty repository, click <b>uploading an existing file</b>. Drag in everything from the unzipped folder ({{ $inside }}), not the folder around them, so {{ $manifest }} is at the top. Press <b>Commit changes</b>.
                </li>
                <li>
                    <b>Add the topic</b>, so Booklet can find it when it searches. On the repository’s page, click the gear next to <b>About</b> and add this under <b>Topics</b>:
                    <span class="share-guide__value"><code>{{ $topic }}</code>
                        <button type="button" class="link-button" x-on:click="copy(@js($topic), 'topic')"><span x-text="copied === 'topic' ? 'Copied' : 'Copy'">Copy</span></button></span>
                </li>
                <li>
                    <b>Make a release.</b> Click <b>Releases</b>, then <b>Create a new release</b>. Under <b>Choose a tag</b>, type the version and pick <b>Create new tag</b>, then <b>Publish release</b>. Booklet installs the newest release.
                    <span class="share-guide__value"><span class="field__hint">Tag:</span> <code>{{ $version }}</code>
                        <button type="button" class="link-button" x-on:click="copy(@js($version), 'tag')"><span x-text="copied === 'tag' ? 'Copied' : 'Copy'">Copy</span></button></span>
                </li>
                <li>
                    <b>Tell people what to type.</b> They add it in <b>{{ $where }}</b>, under <b>From a link or GitHub</b>:
                    <span class="share-guide__value"><code>{{ ($owner ?? 'your-github-name').'/'.$repo }}</code>
                        <button type="button" class="link-button" x-on:click="copy(@js(($owner ?? 'your-github-name').'/'.$repo), 'name')"><span x-text="copied === 'name' ? 'Copied' : 'Copy'">Copy</span></button></span>
                    @unless ($owner) <span class="field__hint">Put your GitHub name before the slash.</span> @endunless
                </li>
            </ol>
            <p class="field__hint" style="margin: 0">
                <b>Changing it later:</b> raise the version in {{ $manifest }} (1.0 → 1.1), download the .zip again, upload the files that changed, and make a new release with the new version. Sites that added it from GitHub can then update{{ $kind === 'insert' ? ' (Booklet checks every day)' : ' with Look for an update' }}.
            </p>
            @if ($code)
                <p class="field__hint" style="margin: 0">{{ $kind === 'theme' ? 'It has templates, so' : 'It’s code, so' }} the people adding it are asked for their password, and told to only add it if they trust you.</p>
            @endif
        </section>
        @endif
    </div>
</details>
