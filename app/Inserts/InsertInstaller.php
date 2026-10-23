<?php

namespace App\Inserts;

use App\Support\SafeFetch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Adding inserts from the shelf (a .zip, a link to one, or a GitHub
 * repository), checking for newer versions, updating and undoing.
 *
 * Nothing of an insert runs until it's added: what it is (name, version…)
 * is read from its insert.php as text (see ManifestReader), and every PHP
 * file in it is checked for mistakes first. It's unpacked to one side
 * ("staged"), shown, and only moved into inserts/ once someone says yes.
 * The copy it replaces is kept, so an update can be undone.
 */
class InsertInstaller
{
    public const MAX_ZIP = 20 * 1024 * 1024;

    public const MAX_UNPACKED = 80 * 1024 * 1024;

    public const MAX_FILES = 3000;

    public function __construct(protected InsertManager $manager) {}

    // Can it be done here? -------------------------------------------------------------------

    /** Why adding from the shelf won't work on this server (null: it will). */
    public function blocked(): ?string
    {
        if (! config('booklet.inserts_install', true)) {
            return 'Adding inserts from here is switched off on this site (BOOKLET_INSERTS_INSTALL in .env).';
        }
        if (! class_exists(ZipArchive::class)) {
            return 'This server’s PHP can’t open .zip files (the zip extension is missing).';
        }
        $path = $this->manager->path();
        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        if (! is_dir($path) || ! is_writable($path)) {
            return 'Booklet isn’t allowed to write to the inserts folder on this server. Your host can change that, or put the insert’s folder in it yourself.';
        }
        foreach ([$this->stagingRoot(), $this->previousRoot()] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (! is_writable($dir)) {
                return 'Booklet isn’t allowed to write to storage/ on this server.';
            }
        }

        return null;
    }

    protected function stagingRoot(): string
    {
        return storage_path('app/private/insert-staging');
    }

    protected function previousRoot(): string
    {
        return storage_path('app/private/insert-previous');
    }

    // Where from --------------------------------------------------------------------------------

    /**
     * What someone typed: "someone/repo", a github.com address, or a link to a .zip.
     *
     * @return array{type: string, repo?: string, url?: string}
     */
    public function parseSource(string $input): array
    {
        $input = trim($input);
        if (preg_match('#^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100}?)(?:\.git)?/?$#i', $input, $m)
            || preg_match('#^([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})$#', $input, $m)) {
            return ['type' => 'github', 'repo' => $m[1].'/'.$m[2]];
        }
        if (preg_match('#^https?://#i', $input) && filter_var($input, FILTER_VALIDATE_URL)) {
            return ['type' => 'url', 'url' => $input];
        }

        throw new RuntimeException('That isn’t a link Booklet understands. Use a link to a .zip, or a GitHub repository like someone/booklet-recipes.');
    }

    /** How a source reads on the shelf. */
    public static function describe(?array $source): ?string
    {
        return match ($source['type'] ?? null) {
            'github' => 'github.com/'.$source['repo'],
            'url' => preg_replace('#^https?://#', '', (string) $source['url']),
            'upload' => 'a file'.(! empty($source['file']) ? ' ('.$source['file'].')' : ''),
            default => null,
        };
    }

    protected function githubHeaders(): array
    {
        $headers = ['Accept' => 'application/vnd.github+json'];
        if ($token = config('booklet.github_token')) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }

    protected function github(string $path): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Booklet'] + $this->githubHeaders())
                ->timeout(15)->connectTimeout(8)->get('https://api.github.com/'.$path);
        } catch (Throwable) {
            throw new RuntimeException('GitHub didn’t answer. Try again in a minute.');
        }
        if ($response->status() === 404) {
            return null;
        }
        if ($response->status() === 403 || $response->status() === 429) {
            throw new RuntimeException('GitHub is asking Booklet to slow down. Try again in an hour.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('GitHub said '.$response->status().'.');
        }

        return (array) $response->json();
    }

    /**
     * Download a source's .zip to a temporary file.
     *
     * @return string the temporary file (the caller deletes it)
     */
    public function download(array $source): string
    {
        if ($source['type'] === 'github') {
            $release = $this->github('repos/'.$source['repo'].'/releases/latest');
            if ($release && ! empty($release['zipball_url'])) {
                $url = (string) $release['zipball_url'];
            } else {
                $repo = $this->github('repos/'.$source['repo'])
                    ?? throw new RuntimeException('There’s no GitHub repository called '.$source['repo'].' (or it’s private).');
                $url = 'https://codeload.github.com/'.$source['repo'].'/zip/refs/heads/'.rawurlencode((string) ($repo['default_branch'] ?? 'main'));
            }
            $headers = str_starts_with($url, 'https://api.github.com/') ? $this->githubHeaders() : [];
        } elseif ($source['type'] === 'url') {
            $url = (string) $source['url'];
            $headers = [];
        } else {
            throw new RuntimeException('This insert was added from a file, so there’s nowhere to fetch it from.');
        }

        $file = SafeFetch::download($url, $why, self::MAX_ZIP, $headers);
        if (! $file) {
            throw new RuntimeException('It couldn’t be downloaded: '.$why.'.');
        }

        return $file;
    }

    // Looking inside ---------------------------------------------------------------------------

    /**
     * Open a .zip and find the insert in it: insert.php at the top, or in
     * the one folder everything is in (as GitHub's downloads are).
     *
     * @return array{zip: ZipArchive, root: string, manifest: array}
     */
    protected function open(string $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That isn’t a .zip file Booklet can open.');
        }

        $tops = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_starts_with($name, '__MACOSX/') || $name === '') {
                continue;
            }
            $tops[explode('/', $name)[0]] = true;
        }
        $root = $zip->locateName('insert.php') !== false ? ''
            : (count($tops) === 1 && $zip->locateName(array_key_first($tops).'/insert.php') !== false ? array_key_first($tops).'/' : null);
        if ($root === null) {
            $zip->close();
            throw new RuntimeException('There’s no insert in it: an insert has an insert.php at the top (or in its one folder).');
        }

        try {
            $manifest = ManifestReader::read((string) $zip->getFromName($root.'insert.php'));
        } catch (\ParseError $e) {
            $zip->close();
            throw new RuntimeException('Its insert.php has a mistake on line '.$e->getLine().': '.$e->getMessage());
        }

        return ['zip' => $zip, 'root' => $root, 'manifest' => $manifest];
    }

    /** The insert's address (its folder name): what it says, or made from its name. */
    protected function keyFor(array $manifest, string $root, string $fallback): string
    {
        foreach ([$manifest['key'] ?? null, isset($manifest['name']) ? Str::slug($manifest['name']) : null,
            rtrim($root, '/'), pathinfo($fallback, PATHINFO_FILENAME)] as $i => $try) {
            // Folder and file names drop "booklet-" and a version or branch on the end ("booklet-recipes-1.2" → recipes).
            $try = $i < 2 ? strtolower((string) $try) : preg_replace(['/^booklet-(insert-)?/', '/-(main|master|v?\d[\w.-]*)$/'], '', Str::slug((string) $try));
            if (preg_match('/^[a-z][a-z0-9-]{0,59}$/', $try)) {
                return $try;
            }
        }

        throw new RuntimeException('Booklet couldn’t tell what to call it. Its insert.php should say \'key\' => \'my-insert\'.');
    }

    /**
     * Unpack a .zip to one side and describe it. Nothing in inserts/ changes.
     *
     * @return string a token for staged() / install()
     */
    public function stage(string $file, array $source, string $label = '', ?string $sameAs = null): string
    {
        if (filesize($file) > self::MAX_ZIP) {
            throw new RuntimeException('It’s bigger than '.(self::MAX_ZIP / 1048576).' MB, which is more than an insert should be.');
        }
        $this->tidy();
        ['zip' => $zip, 'root' => $root, 'manifest' => $manifest] = $this->open($file);
        $key = isset($manifest['key']) || $sameAs === null ? $this->keyFor($manifest, $root, $label ?: $file) : $sameAs;

        $token = Str::lower(Str::random(32));
        $into = $this->stagingRoot().'/'.$token.'/files';
        File::ensureDirectoryExists($into, 0755);

        try {
            $count = $bytes = 0;
            $php = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) $stat['name']);
                if ($root !== '' && ! str_starts_with($name, $root)) {
                    continue; // __MACOSX/ and the like
                }
                $rel = substr($name, strlen($root));
                if ($rel === '' || str_ends_with($rel, '/')) {
                    continue;
                }
                $parts = explode('/', $rel);
                if (str_starts_with($rel, '/') || preg_match('/^[a-z]:/i', $rel) || in_array('..', $parts, true) || in_array('', $parts, true) || str_contains($rel, "\0")) {
                    throw new RuntimeException('It has a file with an odd name ('.Str::limit($rel, 80).'), so it wasn’t opened.');
                }
                if (in_array($parts[0], ['.git', '.github', '__MACOSX'], true) || basename($rel) === '.DS_Store') {
                    continue;
                }
                $zip->getExternalAttributesIndex($i, $os, $attr);
                if ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('It has a shortcut (a symlink) in it ('.Str::limit($rel, 80).'), which inserts can’t have.');
                }
                if (++$count > self::MAX_FILES || ($bytes += (int) $stat['size']) > self::MAX_UNPACKED) {
                    throw new RuntimeException('It unpacks to more than an insert should be ('.self::MAX_FILES.' files or '.(self::MAX_UNPACKED / 1048576).' MB).');
                }

                $target = $into.'/'.$rel;
                File::ensureDirectoryExists(dirname($target), 0755);
                $in = $zip->getStream($name);
                $out = fopen($target, 'wb');
                if (! $in || ! $out) {
                    throw new RuntimeException('A file in it couldn’t be unpacked ('.Str::limit($rel, 80).').');
                }
                $written = stream_copy_to_stream($in, $out, self::MAX_UNPACKED);
                fclose($in);
                fclose($out);
                if ($written !== (int) $stat['size']) {
                    throw new RuntimeException('A file in it isn’t what the .zip says it is ('.Str::limit($rel, 80).').');
                }
                if (str_ends_with(strtolower($rel), '.php')) {
                    $php[] = $rel;
                }
            }

            // Every PHP file checked for mistakes now, not when it's half-switched-on.
            foreach ($php as $rel) {
                try {
                    token_get_all((string) file_get_contents($into.'/'.$rel), TOKEN_PARSE);
                } catch (\ParseError $e) {
                    throw new RuntimeException('There’s a mistake in '.$rel.' on line '.$e->getLine().': '.$e->getMessage());
                }
            }
        } catch (Throwable $e) {
            $zip->close();
            File::deleteDirectory($this->stagingRoot().'/'.$token);
            throw $e instanceof RuntimeException ? $e : new RuntimeException('It couldn’t be unpacked: '.$e->getMessage());
        }
        $zip->close();

        $here = $this->manager->find($key);
        $meta = [
            'key' => $key,
            'name' => $manifest['name'] ?? Str::headline($key),
            'about' => $manifest['about'] ?? '',
            'version' => $manifest['version'] ?? '1.0',
            'author' => $manifest['author'] ?? null,
            'needs' => $manifest['needs'] ?? null,
            'homepage' => $manifest['homepage'] ?? null,
            'sticker' => $manifest['sticker'] ?? 'sand',
            'files' => $count,
            'bytes' => $bytes,
            'migrations' => is_dir($into.'/migrations'),
            'source' => $source,
            'replaces' => $here ? ['version' => $here->version(), 'author' => $here->author(), 'name' => $here->name()] : null,
            'staged_at' => now()->toIso8601String(),
        ];
        file_put_contents($this->stagingRoot().'/'.$token.'/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $token;
    }

    /** Fetch from a source and stage it. */
    public function stageFrom(array $source): string
    {
        $file = $this->download($source);
        // Already on the shelf from this same place: it's that insert, whatever it's called now.
        $same = $this->manager->all()->first(fn (Insert $i) => ($s = $i->source()) && ($s['type'] ?? null) === $source['type']
            && strcasecmp((string) ($s['repo'] ?? $s['url'] ?? ''), (string) ($source['repo'] ?? $source['url'] ?? '')) === 0);
        try {
            return $this->stage($file, $source, $source['repo'] ?? basename((string) parse_url((string) ($source['url'] ?? ''), PHP_URL_PATH)), $same?->key);
        } finally {
            @unlink($file);
        }
    }

    public function staged(string $token): ?array
    {
        if (! preg_match('/^[a-z0-9]{32}$/', $token) || ! is_file($file = $this->stagingRoot().'/'.$token.'/meta.json')) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        if (! is_array($meta)) {
            return null;
        }
        $meta['fits'] = empty($meta['needs']) || version_compare((string) config('booklet.version', '1.0'), (string) $meta['needs'], '>=');
        $meta['newer'] = $meta['replaces'] ? version_compare((string) $meta['version'], (string) $meta['replaces']['version'], '>') : null;

        return $meta;
    }

    public function discard(string $token): void
    {
        if (preg_match('/^[a-z0-9]{32}$/', $token)) {
            File::deleteDirectory($this->stagingRoot().'/'.$token);
        }
    }

    /** Staged inserts nobody said yes to, after a day. */
    public function tidy(): void
    {
        foreach (glob($this->stagingRoot().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < now()->subDay()->timestamp) {
                File::deleteDirectory($dir);
            }
        }
    }

    // Putting it in ---------------------------------------------------------------------------

    /**
     * Move a staged insert into inserts/. A new one arrives switched off; an
     * update keeps its switch, settings and records, and the copy it
     * replaces is kept for undo().
     *
     * @return array{key: string, name: string, version: string, updated: bool, from: ?string}
     */
    public function install(string $token): array
    {
        if ($why = $this->blocked()) {
            throw new RuntimeException($why);
        }
        $meta = $this->staged($token) ?? throw new RuntimeException('That insert isn’t waiting any more. Add it again.');
        if (! $meta['fits']) {
            throw new RuntimeException($meta['name'].' needs Booklet '.$meta['needs'].' or newer; this is '.config('booklet.version').'.');
        }

        $key = $meta['key'];
        $dest = $this->manager->path().'/'.$key;
        $from = null;

        if (is_dir($dest)) {
            $old = $this->manager->find($key);
            $from = $old?->version();
            $keep = $this->previousRoot().'/'.$key;
            File::deleteDirectory($keep);
            @unlink($keep.'.json');
            $this->move($dest, $keep);
            file_put_contents($keep.'.json', json_encode(['version' => $from, 'replaced_at' => now()->toIso8601String()]));
        }
        $this->move($this->stagingRoot().'/'.$token.'/files', $dest);
        File::deleteDirectory($this->stagingRoot().'/'.$token);
        $this->freshen($dest);

        $values = ['source' => json_encode($meta['source']), 'latest_version' => null, 'latest_checked_at' => now()];
        $enabled = (bool) DB::table('inserts')->where('key', $key)->value('enabled');
        if ($enabled) {
            // Switched on already: bring its tables up to date, and give it a fresh start if it had been torn out.
            if (is_dir($dest.'/migrations')) {
                Artisan::call('migrate', ['--path' => $dest.'/migrations', '--realpath' => true, '--force' => true]);
            }
            $values += ['version' => $meta['version'], 'torn_out_at' => null, 'error' => null, 'error_at' => null];
        }
        $this->manager->record($key, $values);
        $this->manager->forget();

        return ['key' => $key, 'name' => $meta['name'], 'version' => (string) $meta['version'], 'updated' => $from !== null, 'from' => $from];
    }

    /** The version an update replaced, if it can be put back. */
    public function previousVersion(string $key): ?string
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,59}$/', $key) || ! is_dir($this->previousRoot().'/'.$key)) {
            return null;
        }
        $meta = json_decode((string) @file_get_contents($this->previousRoot().'/'.$key.'.json'), true);

        return (string) ($meta['version'] ?? '?');
    }

    /** Put back the copy an update replaced. */
    public function undo(string $key): string
    {
        $version = $this->previousVersion($key) ?? throw new RuntimeException('There’s no earlier copy to go back to.');
        if ($why = $this->blocked()) {
            throw new RuntimeException($why);
        }
        $dest = $this->manager->path().'/'.$key;
        $keep = $this->previousRoot().'/'.$key;

        if (is_dir($dest)) {
            File::deleteDirectory($dest);
        }
        $this->move($keep, $dest);
        @unlink($keep.'.json');
        $this->freshen($dest);

        $enabled = (bool) DB::table('inserts')->where('key', $key)->value('enabled');
        $this->manager->record($key, ['latest_checked_at' => null] + ($enabled ? ['version' => $version, 'torn_out_at' => null, 'error' => null, 'error_at' => null] : []));
        $this->manager->forget();

        return $version;
    }

    /** Take an insert off the shelf altogether: its folder and any earlier copy. */
    public function remove(string $key): void
    {
        if ($why = $this->blocked()) {
            throw new RuntimeException($why);
        }
        if (! preg_match('/^[a-z][a-z0-9-]{0,59}$/', $key)) {
            return;
        }
        File::deleteDirectory($this->manager->path().'/'.$key);
        File::deleteDirectory($this->previousRoot().'/'.$key);
        @unlink($this->previousRoot().'/'.$key.'.json');
        $this->manager->forget();
    }

    protected function move(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($to), 0755);
        if (! @rename($from, $to)) {
            // Another disk: copy, then tidy up.
            if (! File::copyDirectory($from, $to)) {
                throw new RuntimeException('The files couldn’t be moved into place.');
            }
            File::deleteDirectory($from);
        }
    }

    /** New code, so PHP mustn't keep using its remembered copy of the old. */
    protected function freshen(string $dir): void
    {
        clearstatcache();
        if (! function_exists('opcache_invalidate')) {
            return;
        }
        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() === 'php') {
                @opcache_invalidate($file->getPathname(), true);
            }
        }
    }

    // Newer versions ---------------------------------------------------------------------------

    /** The newest version a source has, read without unpacking anything (null: couldn't tell). */
    public function latest(array $source): ?string
    {
        if ($source['type'] === 'github') {
            $release = $this->github('repos/'.$source['repo'].'/releases/latest');
            if ($release && ! empty($release['tag_name'])) {
                return ltrim((string) $release['tag_name'], 'vV');
            }
        }
        if (! in_array($source['type'], ['github', 'url'], true)) {
            return null;
        }
        if ($source['type'] === 'github') {
            // No releases: the version in insert.php on the default branch, read as text
            // (raw.githubusercontent.com doesn't count against GitHub's limits, and nothing is downloaded).
            $raw = rescue(fn () => Http::withHeaders(['User-Agent' => 'Booklet'])->timeout(10)->connectTimeout(6)
                ->get('https://raw.githubusercontent.com/'.$source['repo'].'/HEAD/insert.php'), null, false);
            if ($raw && $raw->successful()) {
                return ManifestReader::read($raw->body())['version'] ?? null;
            }
        }

        $file = $this->download($source);
        try {
            ['zip' => $zip, 'manifest' => $manifest] = $this->open($file);
            $zip->close();

            return isset($manifest['version']) ? (string) $manifest['version'] : null;
        } finally {
            @unlink($file);
        }
    }

    /**
     * Look for newer versions of inserts added from a link (the scheduler
     * does this daily). A problem with one doesn't stop the others.
     *
     * @return array<string, string> inserts with an update => its version
     */
    /** Inserts whose source couldn't be reached by the last check(). */
    public array $unreached = [];

    public function check(?string $only = null): array
    {
        $ready = [];
        $this->unreached = [];
        foreach ($this->manager->all() as $insert) {
            $source = $insert->source();
            if (($only && $insert->key !== $only) || ! in_array($source['type'] ?? null, ['github', 'url'], true)) {
                continue;
            }
            try {
                $latest = $this->latest($source);
            } catch (Throwable $e) {
                report($e);
                $this->unreached[] = $insert->key;

                continue; // couldn't ask: what was found before (an update waiting, say) stays as it was
            }
            $this->manager->record($insert->key, ['latest_version' => $latest, 'latest_checked_at' => now()]);
            if ($latest && version_compare($latest, $insert->version(), '>')) {
                $ready[$insert->key] = $latest;
            }
        }
        $this->manager->forget();
        $this->tidy();

        return $ready;
    }
}
