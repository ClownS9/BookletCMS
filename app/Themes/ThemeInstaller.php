<?php

namespace App\Themes;

use App\Http\Controllers\ThemeAssetController;
use App\Inserts\InsertInstaller;
use App\Support\Look;
use App\Support\PageCache;
use App\Support\SafeUpload;
use App\Support\StaticSite;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Adding themes from The cover: a .zip, a link to one, or a GitHub
 * repository. Like inserts, a theme is unpacked to one side and looked at
 * before anything changes, and the copy an update replaces is kept.
 *
 * What may be in one is narrow: theme.json, files in assets/ that a page
 * can use, and (for a theme with code) templates for the public site in
 * views/. A cover pack's styles are read through too: nothing from other
 * websites and nothing that runs, so one is safe to add from anyone.
 */
class ThemeInstaller
{
    public const MAX_ZIP = 15 * 1024 * 1024;

    public const MAX_UNPACKED = 40 * 1024 * 1024;

    public const MAX_FILES = 600;

    public const MAX_CSS = 512 * 1024;

    /** Files at the top a theme may have beside theme.json. */
    public const NOTES = ['readme.md', 'readme.txt', 'license', 'license.md', 'license.txt', 'changelog.md'];

    // Can it be done here? -------------------------------------------------------------------

    /** Why adding themes won't work on this server (null: it will). $code: one with templates or scripts. */
    public function blocked(bool $code = false): ?string
    {
        if ($code && ! config('booklet.inserts_install', true)) {
            return 'Adding themes with their own templates is switched off on this site (BOOKLET_INSERTS_INSTALL in .env). Cover packs still work, or put the theme’s folder in themes/ yourself.';
        }
        if (! class_exists(ZipArchive::class)) {
            return 'This server’s PHP can’t open .zip files (the zip extension is missing).';
        }
        foreach ([ThemeManager::path(), $this->stagingRoot(), $this->previousRoot()] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (! is_dir($dir) || ! is_writable($dir)) {
                return $dir === ThemeManager::path()
                    ? 'Booklet isn’t allowed to write to the themes folder on this server. Your host can change that, or put the theme’s folder in it yourself.'
                    : 'Booklet isn’t allowed to write to storage/ on this server.';
            }
        }

        return null;
    }

    protected function stagingRoot(): string
    {
        return StaticSite::privatePath('theme-staging');
    }

    protected function previousRoot(): string
    {
        return StaticSite::privatePath('theme-previous');
    }

    // Where from (the same as inserts: a link to a .zip, or a GitHub repository) -------------

    public function parseSource(string $input): array
    {
        return app(InsertInstaller::class)->parseSource($input);
    }

    public function stageFrom(array $source): string
    {
        $file = app(InsertInstaller::class)->download($source);
        try {
            return $this->stage($file, $source, $source['repo'] ?? basename((string) parse_url((string) ($source['url'] ?? ''), PHP_URL_PATH)));
        } finally {
            @unlink($file);
        }
    }

    // Looking inside ---------------------------------------------------------------------------

    /**
     * Unpack a .zip to one side, check everything in it, and describe it.
     * Nothing in themes/ changes.
     *
     * @return string a token for staged() / install()
     */
    public function stage(string $file, array $source, string $label = ''): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('This server’s PHP can’t open .zip files (the zip extension is missing).');
        }
        if (filesize($file) > self::MAX_ZIP) {
            throw new RuntimeException('It’s bigger than '.(self::MAX_ZIP / 1048576).' MB, which is more than a theme should be.');
        }
        $this->tidy();

        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That isn’t a .zip file Booklet can open.');
        }
        $root = $this->rootOf($zip);
        if ($root === null) {
            $zip->close();
            throw new RuntimeException('There’s no theme in it: a theme has a theme.json at the top (or in its one folder).');
        }

        $fallback = preg_replace(['/^booklet-(theme-)?/', '/-(main|master|v?\d[\w.-]*)$/'], '', Str::slug(rtrim($root, '/') ?: pathinfo($label ?: $file, PATHINFO_FILENAME)));
        ['manifest' => $manifest, 'problems' => $problems] = ThemeManifest::read((string) $zip->getFromName($root.'theme.json'), (string) $fallback);
        if (($manifest['key'] ?? '') === '' || ! preg_match(ThemeManifest::KEY, $manifest['key'])) {
            $zip->close();
            throw new RuntimeException(isset($manifest['key']) ? 'Booklet couldn’t tell what to call it. Its theme.json should say "key": "my-theme".' : $problems[0]);
        }
        if ($manifest['key'] === 'booklet') {
            $zip->close();
            throw new RuntimeException('“booklet” is the name of Booklet’s own cover. Give the theme another key in its theme.json.');
        }

        $token = Str::lower(Str::random(32));
        $into = $this->stagingRoot().'/'.$token.'/files';
        File::ensureDirectoryExists($into, 0755);

        try {
            ['count' => $count, 'bytes' => $bytes, 'templates' => $templates, 'scripts' => $scripts, 'styles' => $styles] = $this->unpack($zip, $root, $into);
            $code = $templates !== [] || $manifest['js'] !== [];

            foreach ($manifest['js'] as $js) {
                if (! is_file($into.'/assets/'.$js)) {
                    $problems[] = 'theme.json lists '.$js.', which isn’t in assets/.';
                }
            }
            foreach ($scripts as $js) {
                if (! in_array($js, $manifest['js'], true)) {
                    throw new RuntimeException('It has a script (assets/'.$js.') its theme.json doesn’t list. A cover pack can’t have scripts; a theme with code lists them under "js".');
                }
            }
            foreach ($manifest['css'] as $css) {
                if (! is_file($into.'/assets/'.$css)) {
                    $problems[] = 'theme.json lists '.$css.', which isn’t in assets/.';
                }
            }
            // A cover pack's styles: read through, so it can only change how things look.
            if (! $code) {
                foreach ($styles as $css) {
                    if ($why = self::checkCss((string) file_get_contents($into.'/assets/'.$css), $into.'/assets', $css)) {
                        throw new RuntimeException('assets/'.$css.' '.$why);
                    }
                }
            }
        } catch (Throwable $e) {
            $zip->close();
            File::deleteDirectory($this->stagingRoot().'/'.$token);
            throw $e instanceof RuntimeException ? $e : new RuntimeException('It couldn’t be unpacked: '.$e->getMessage());
        }
        $zip->close();

        $here = ThemeManager::find($manifest['key']);
        $meta = [
            'key' => $manifest['key'],
            'name' => $manifest['name'],
            'about' => $manifest['about'],
            'version' => $manifest['version'],
            'author' => $manifest['author'],
            'homepage' => $manifest['homepage'],
            'needs' => $manifest['needs'],
            'code' => $code,
            'templates' => $templates,
            'scripts' => $manifest['js'],
            'presets' => array_values(array_map(fn ($p) => $p['name'], $manifest['presets'])),
            'fonts' => array_values(array_map(fn ($f) => $f[0], $manifest['fonts'])),
            'options' => array_values(array_map(fn ($o) => $o['label'], $manifest['options'])),
            'problems' => array_values(array_unique($problems)),
            'files' => $count,
            'bytes' => $bytes,
            'source' => $source,
            'replaces' => $here ? ['version' => $here->version(), 'name' => $here->name(), 'code' => $here->hasCode()] : null,
            'staged_at' => now()->toIso8601String(),
        ];
        file_put_contents($this->stagingRoot().'/'.$token.'/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $token;
    }

    /** theme.json at the top, or in the one folder everything is in (as GitHub's downloads are). */
    protected function rootOf(ZipArchive $zip): ?string
    {
        if ($zip->locateName('theme.json') !== false) {
            return '';
        }
        $tops = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name !== '' && ! str_starts_with($name, '__MACOSX/')) {
                $tops[explode('/', $name)[0]] = true;
            }
        }

        return count($tops) === 1 && $zip->locateName(array_key_first($tops).'/theme.json') !== false ? array_key_first($tops).'/' : null;
    }

    /**
     * Unpack, refusing anything a theme mustn't have; check templates for
     * mistakes and clean SVG pictures on the way.
     *
     * @return array{count: int, bytes: int, templates: list<string>, scripts: list<string>, styles: list<string>}
     */
    protected function unpack(ZipArchive $zip, string $root, string $into): array
    {
        $count = $bytes = 0;
        $templates = $scripts = $styles = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) $stat['name']);
            if ($root !== '' && ! str_starts_with($name, $root)) {
                continue;
            }
            $rel = substr($name, strlen($root));
            if ($rel === '' || str_ends_with($rel, '/')) {
                continue;
            }
            $parts = explode('/', $rel);
            if (str_starts_with($rel, '/') || preg_match('/^[a-z]:/i', $rel) || in_array('..', $parts, true) || in_array('', $parts, true) || str_contains($rel, "\0")) {
                throw new RuntimeException('It has a file with an odd name ('.Str::limit($rel, 80).'), so it wasn’t opened.');
            }
            if (in_array($parts[0], ['.git', '.github', '__MACOSX'], true) || basename($rel) === '.DS_Store' || basename($rel) === '.gitignore') {
                continue;
            }
            $zip->getExternalAttributesIndex($i, $os, $attr);
            if ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('It has a shortcut (a symlink) in it ('.Str::limit($rel, 80).'), which themes can’t have.');
            }

            // What may be where.
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (count($parts) === 1) {
                if ($rel !== 'theme.json' && ! in_array(strtolower($rel), self::NOTES, true)) {
                    throw new RuntimeException('It has '.$rel.' at the top. A theme has theme.json, a README or LICENSE, and the folders assets/ and views/.');
                }
            } elseif ($parts[0] === 'assets') {
                if (! isset(ThemeAssetController::TYPES[$ext])) {
                    throw new RuntimeException('assets/ can have styles, fonts, pictures and scripts; '.Str::limit($rel, 80).' isn’t one of those.');
                }
                $asset = substr($rel, 7);
                match ($ext) {
                    'js' => $scripts[] = $asset,
                    'css' => $styles[] = $asset,
                    default => null,
                };
            } elseif ($parts[0] === 'views') {
                $view = substr($rel, 6);
                $allowed = collect(Theme::VIEW_FOLDERS)->contains(fn ($f) => str_starts_with($view, $f.'/'));
                if (! str_ends_with($rel, '.blade.php')) {
                    throw new RuntimeException('views/ is for templates (.blade.php); '.Str::limit($rel, 80).' isn’t one.');
                }
                if (! $allowed) {
                    throw new RuntimeException('A theme’s templates are for the public site: views/site/, views/components/site/ or views/errors/. '.Str::limit($rel, 80).' is somewhere else.');
                }
                $templates[] = str_replace('/', '.', substr($view, 0, -10));
            } else {
                throw new RuntimeException('It has '.Str::limit($rel, 80).'. A theme keeps its files in assets/ and its templates in views/.');
            }

            if (++$count > self::MAX_FILES || ($bytes += (int) $stat['size']) > self::MAX_UNPACKED) {
                throw new RuntimeException('It unpacks to more than a theme should be ('.self::MAX_FILES.' files or '.(self::MAX_UNPACKED / 1048576).' MB).');
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

            if ($ext === 'svg') {
                $clean = SafeUpload::cleanSvg((string) file_get_contents($target));
                if ($clean === null) {
                    throw new RuntimeException(Str::limit($rel, 80).' isn’t an SVG picture Booklet can read.');
                }
                file_put_contents($target, $clean);
            }
            if (str_ends_with($rel, '.blade.php')) {
                if ($why = self::checkTemplate((string) file_get_contents($target))) {
                    throw new RuntimeException('There’s a mistake in '.$rel.' '.$why);
                }
            }
        }

        sort($templates);

        return compact('count', 'bytes', 'templates', 'scripts', 'styles');
    }

    /** A template turned into PHP and read for mistakes, without running it (null: fine). */
    public static function checkTemplate(string $blade): ?string
    {
        try {
            $php = app('blade.compiler')->compileString($blade);
            $tokens = token_get_all($php, TOKEN_PARSE);
            unset($tokens);
        } catch (\ParseError $e) {
            return '(it doesn’t make sense as PHP once its Blade is read: '.$e->getMessage().').';
        } catch (Throwable $e) {
            return '('.Str::limit($e->getMessage(), 160).').';
        }

        return null;
    }

    /**
     * Read a cover pack's styles for anything that isn't styling: files from
     * other websites, @import, and the old ways to run script from CSS.
     * Escapes ("\75 rl(") are read as what they stand for first.
     *
     * @return string|null why it can't be used (null: it's fine)
     */
    public static function checkCss(string $css, string $assets, string $file): ?string
    {
        if (strlen($css) > self::MAX_CSS) {
            return 'is bigger than '.(self::MAX_CSS / 1024).' KB, which is more than a cover pack’s styles should be.';
        }
        $plain = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $plain = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)/s', function ($m) {
            if ($m[1] !== '') {
                $code = hexdec($m[1]);

                return $code > 0 && $code < 0x110000 ? mb_chr((int) $code, 'UTF-8') : '';
            }

            return $m[2];
        }, $plain);
        $lower = strtolower($plain);

        $squashed = (string) preg_replace('/\s+/', '', $lower);
        foreach (['/@import/' => 'uses @import. Put every style in the files theme.json lists.',
            '/expression\(/' => 'uses expression(), which runs script in old browsers.',
            '/javascript:/' => 'has a javascript: link.',
            '/vbscript:/' => 'has a vbscript: link.',
            '/-moz-binding/' => 'uses -moz-binding, which runs script in old browsers.',
            '/(?<![a-z-])behavior:/' => 'uses behavior:, which runs script in old browsers.',
            '/@namespace/' => 'uses @namespace.'] as $pattern => $why) {
            if (preg_match($pattern, $squashed)) {
                return $why;
            }
        }

        // Every url(): a file in the pack, or a picture or font written into the CSS itself.
        $dir = trim(dirname($file), '.');
        if (! preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', $plain, $m)) {
            $m = [[], [], []];
        }
        foreach ($m[2] as $url) {
            $url = trim($url);
            if (preg_match('#^data:(image/(png|jpe?g|gif|webp|avif)|font/|application/(font|x-font))#i', $url) || str_starts_with($url, '#')) {
                continue;
            }
            $path = self::normalise(($dir !== '' ? $dir.'/' : '').strtok($url, '?#'));
            if (preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $url) || str_starts_with($url, '/') || $path === null || ! ThemeManifest::file($path)
                || ! is_file($assets.'/'.$path)) {
                return 'loads '.Str::limit($url, 60).', which isn’t a file in the pack. A cover pack can only use its own fonts and pictures.';
            }
        }
        // Strings that are addresses elsewhere (image-set("…"), src: "…"); not text like content: "// ".
        if (preg_match('#["\']\s*(https?:)?//[a-z0-9\[]#i', $plain)) {
            return 'points at another website. A cover pack can only use its own fonts and pictures.';
        }

        return null;
    }

    /** "css/../fonts/a.woff2" → "fonts/a.woff2"; null when it climbs out of assets/. */
    protected static function normalise(string $path): ?string
    {
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            if ($part === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);
            } else {
                $out[] = $part;
            }
        }

        return implode('/', $out);
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

    public function tidy(): void
    {
        foreach (glob($this->stagingRoot().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < now()->subDay()->timestamp) {
                File::deleteDirectory($dir);
            }
        }
    }

    // Putting it in ---------------------------------------------------------------------------

    /** @return array{key: string, name: string, version: string, updated: bool, from: ?string} */
    public function install(string $token): array
    {
        $meta = $this->staged($token) ?? throw new RuntimeException('That theme isn’t waiting any more. Add it again.');
        if ($why = $this->blocked((bool) $meta['code'])) {
            throw new RuntimeException($why);
        }
        if (! $meta['fits']) {
            throw new RuntimeException($meta['name'].' needs Booklet '.$meta['needs'].' or newer; this is '.config('booklet.version').'.');
        }

        $key = $meta['key'];
        $dest = ThemeManager::path().'/'.$key;
        $from = null;
        if (is_dir($dest)) {
            $from = ThemeManager::find($key)?->version();
            $keep = $this->previousRoot().'/'.$key;
            File::deleteDirectory($keep);
            @unlink($keep.'.json');
            $this->move($dest, $keep);
            file_put_contents($keep.'.json', json_encode(['version' => $from, 'replaced_at' => now()->toIso8601String()]));
        }
        $this->move($this->stagingRoot().'/'.$token.'/files', $dest);
        File::deleteDirectory($this->stagingRoot().'/'.$token);
        $this->remember($key, in_array($meta['source']['type'] ?? null, ['github', 'url'], true) ? $meta['source'] : null);
        $this->settle($key);

        return ['key' => $key, 'name' => $meta['name'], 'version' => (string) $meta['version'], 'updated' => $from !== null, 'from' => $from];
    }

    public function previousVersion(string $key): ?string
    {
        if (! preg_match(ThemeManifest::KEY, $key) || ! is_dir($this->previousRoot().'/'.$key)) {
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
        $dest = ThemeManager::path().'/'.$key;
        File::deleteDirectory($dest);
        $this->move($this->previousRoot().'/'.$key, $dest);
        @unlink($this->previousRoot().'/'.$key.'.json');
        $this->settle($key);

        return $version;
    }

    /** Take a theme off the shelf: its folder and any earlier copy. Not the one the site wears. */
    public function remove(string $key): void
    {
        if (! preg_match(ThemeManifest::KEY, $key)) {
            return;
        }
        $saved = Look::clean(rescue(fn () => (array) (\App\Booklet\Facades\Booklet::globals('site')?->get('look') ?? []), [], false));
        if ($saved['theme'] === $key) {
            throw new RuntimeException('The site wears this theme. Choose another cover first, then take it off the shelf.');
        }
        if ($why = $this->blocked()) {
            throw new RuntimeException($why);
        }
        File::deleteDirectory(ThemeManager::path().'/'.$key);
        File::deleteDirectory($this->previousRoot().'/'.$key);
        @unlink($this->previousRoot().'/'.$key.'.json');
        ThemeManager::clearError($key);
        $this->remember($key, null);
        ThemeManager::forget();
    }

    // Where each came from (a link or GitHub), to fetch it again for an update ---------------

    protected function sourcesFile(): string
    {
        return StaticSite::privatePath('theme-sources.json');
    }

    public function sourceOf(string $key): ?array
    {
        $saved = json_decode((string) @file_get_contents($this->sourcesFile()), true);
        $source = is_array($saved) ? ($saved[$key] ?? null) : null;

        return is_array($source) && in_array($source['type'] ?? null, ['github', 'url'], true) ? $source : null;
    }

    protected function remember(string $key, ?array $source): void
    {
        $saved = json_decode((string) @file_get_contents($this->sourcesFile()), true);
        $saved = is_array($saved) ? $saved : [];
        if ($source) {
            $saved[$key] = $source;
        } else {
            unset($saved[$key]);
        }
        File::ensureDirectoryExists(dirname($this->sourcesFile()));
        file_put_contents($this->sourcesFile(), json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** After the files change: PHP and the views forget the old copy, the site's pages are made afresh. */
    protected function settle(string $key): void
    {
        clearstatcache();
        ThemeManager::forget();
        ThemeManager::clearError($key);
        if (function_exists('opcache_reset')) {
            @opcache_reset(); // compiled templates of the old copy
        }
        PageCache::flush();
    }

    protected function move(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($to), 0755);
        if (! @rename($from, $to)) {
            if (! File::copyDirectory($from, $to)) {
                throw new RuntimeException('The files couldn’t be moved into place.');
            }
            File::deleteDirectory($from);
        }
    }
}
