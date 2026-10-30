<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Term;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * The public site as plain files: every page as an index.html in its own
 * folder, with the pictures, styles and scripts they use, ready for GitHub
 * Pages, Netlify or any host that serves files (no PHP needed there).
 *
 * Pages are made by asking Booklet itself for each one, as a visitor would,
 * with every address written for where the files will live ("published
 * address"). What needs a server is turned into something that doesn't:
 * search runs in the browser from a small index, the blog's page 2, 3… are
 * folders, and forms (comments, contact, new posts by email) are left out.
 */
class StaticSite
{
    /** Changes wait this long for things to go quiet before publishing again. */
    public const QUIET_MINUTES = 2;

    public const DIRTY = 'booklet.static.dirty_at';

    /** @var array<string, true> */
    protected array $written = [];

    /** @var array<string, true> files to fetch: path => true */
    protected array $files = [];

    protected string $base;

    protected string $basePath;

    /** @var list<string> addresses the site is reached at besides the published one (rewritten to it) */
    protected array $oldRoots = [];

    public function __construct(protected string $out) {}

    // Settings -----------------------------------------------------------------

    /** Where Booklet keeps its own working files (tests keep theirs elsewhere, so they never touch yours). */
    public static function privatePath(string $name): string
    {
        return rtrim((string) config('booklet.paths.private', storage_path('app/private')), '/').'/'.$name;
    }

    protected static function settingsFile(): string
    {
        return self::privatePath('static-site.json');
    }

    /** @return array{address: string, github: bool, auto: bool, domain: string} */
    public static function settings(): array
    {
        $saved = rescue(fn () => json_decode((string) @file_get_contents(self::settingsFile()), true), [], false);
        $saved = is_array($saved) ? $saved : [];

        return [
            'address' => (string) ($saved['address'] ?? ''),
            'github' => (bool) ($saved['github'] ?? false),
            'auto' => (bool) ($saved['auto'] ?? false),
            'domain' => (string) ($saved['domain'] ?? ''),
        ];
    }

    public static function saveSettings(array $changes): void
    {
        $settings = array_merge(self::settings(), array_intersect_key($changes, array_flip(['address', 'github', 'auto', 'domain'])));
        File::ensureDirectoryExists(dirname(self::settingsFile()));
        file_put_contents(self::settingsFile(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Where the last build went, what it made, and whether it was sent. */
    public static function last(): array
    {
        return (array) Cache::get('booklet.static.last', []);
    }

    public static function folder(): string
    {
        return self::privatePath('static-site');
    }

    /** Where the files will live: the address set here, or GitHub Pages' address for the connected repository, or the site's own. */
    public static function address(): string
    {
        $set = rtrim(self::settings()['address'], '/');
        if ($set !== '') {
            return $set;
        }
        if (self::settings()['domain'] !== '') {
            return 'https://'.self::settings()['domain'];
        }
        if (self::settings()['github'] && ($pages = self::githubPagesAddress())) {
            return $pages;
        }

        return rtrim((string) (app(Site::class)->setting('site_url') ?: config('app.url')), '/');
    }

    /** https://owner.github.io/repo (or https://owner.github.io for a repository called owner.github.io). */
    public static function githubPagesAddress(): ?string
    {
        $web = VersionControl::webUrl();
        if (! $web || ! preg_match('#^https://github\.com/([^/]+)/([^/]+)$#i', $web, $m)) {
            return null;
        }
        $owner = strtolower($m[1]);

        return strtolower($m[2]) === $owner.'.github.io' ? 'https://'.$owner.'.github.io' : 'https://'.$owner.'.github.io/'.$m[2];
    }

    // Changes, and publishing again by itself -----------------------------------------

    /** Something readers see changed (called from Booklet's change events). */
    public static function changed(): void
    {
        if (self::settings()['auto']) {
            Cache::put(self::DIRTY, now()->timestamp, now()->addDays(7));
        }
    }

    public static function due(): bool
    {
        $at = Cache::get(self::DIRTY);

        return self::settings()['auto'] && $at && now()->timestamp - (int) $at >= self::QUIET_MINUTES * 60;
    }

    // Building ---------------------------------------------------------------------

    /**
     * Make the whole site as files. Returns what was made.
     *
     * @return array{pages: int, files: int, bytes: int, address: string, folder: string, at: string, skipped: list<string>}
     */
    public static function build(?string $address = null, ?string $out = null): array
    {
        $builder = new self($out ?? self::folder());

        return $builder->run(rtrim($address ?? self::address(), '/'));
    }

    protected function run(string $address): array
    {
        if (! preg_match('#^https?://[^/\s]+(/[^\s]*)?$#i', $address)) {
            throw new RuntimeException('The published address should look like https://example.com or https://you.github.io/site.');
        }
        $this->base = $address;
        $this->basePath = rtrim((string) parse_url($address, PHP_URL_PATH), '/');

        // A fresh folder each time (it's only ever made by this).
        File::deleteDirectory($this->out);
        File::ensureDirectoryExists($this->out);

        // Addresses pages may still use (where Booklet runs, where files are kept, the site's own) → the published one.
        $this->oldRoots = collect([
            config('app.url'),
            rescue(fn () => preg_replace('#/storage/?$#', '', Disk::of('public')->url('')), null, false),
            app(Site::class)->setting('site_url'),
            url('/'),
        ])->filter()->map(fn ($r) => rtrim((string) $r, '/'))->reject(fn ($r) => $r === '' || $r === $address)->unique()->values()->all();

        $original = app()->bound('request') ? app('request') : null;
        $skipped = [];
        URL::forceRootUrl($address);
        URL::forceScheme((string) parse_url($address, PHP_URL_SCHEME));
        // Paged lists write their "next page" links from the published address too.
        Paginator::currentPathResolver(fn () => url(app('request')->path() === '/' ? '/' : app('request')->path()));
        Cache::forget(self::DIRTY);

        try {
            foreach ($this->pages() as $path) {
                if (! $this->page($path)) {
                    $skipped[] = $path;
                }
            }
            $this->page('/search', 'search/index.html');
            $this->notFound();
            foreach (['/feed.xml', '/sitemap.xml', '/robots.txt'] as $path) {
                $this->plain($path);
            }
            $this->searchIndex();
            $this->copyFiles();

            // GitHub Pages: serve files as they are (no Jekyll), and the custom domain if there is one.
            file_put_contents($this->out.'/.nojekyll', '');
            if (($domain = self::settings()['domain']) !== '') {
                file_put_contents($this->out.'/CNAME', $domain."\n");
            }
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
            Paginator::currentPathResolver(fn () => app('request')->url());
            if ($original) {
                app()->instance('request', $original);
                Facade::clearResolvedInstance('request');
            }
        }

        $made = collect(File::allFiles($this->out));
        $result = [
            'pages' => count(array_filter(array_keys($this->written), fn ($f) => str_ends_with($f, '.html'))),
            'files' => $made->count(),
            'bytes' => (int) $made->sum(fn ($f) => $f->getSize()),
            'address' => $address,
            'folder' => $this->out,
            'at' => now()->toIso8601String(),
            'skipped' => $skipped,
        ];
        Cache::forever('booklet.static.last', $result + ['sent' => null, 'error' => null]);

        return $result;
    }

    /** Every public page's path: home, the blog (and its pages), every published entry, every category and tag. */
    protected function pages(): array
    {
        $site = app(Site::class);
        $paths = ['/'];
        $blog = $this->pathOf($site->blogUrl());
        $paths[] = $blog;

        foreach ($site->published() as $entry) {
            /** @var Entry $entry */
            $paths[] = $this->pathOf($site->url($entry));
        }
        foreach (Booklet::terms()->all() as $term) {
            /** @var Term $term */
            if ($uri = $term->attribute('uri')) {
                $paths[] = '/'.trim((string) $uri, '/');
            }
        }
        // Pages inserts make (Events' /events, the page builder's pages…).
        foreach (rescue(fn () => app(\App\Inserts\InsertManager::class)->publicPages(), [], false) as $page) {
            $paths[] = $page['path'];
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /** A page's path on this site (without the published address's own folder, e.g. /repo on GitHub Pages). */
    protected function pathOf(string $url): string
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($this->basePath !== '' && ($path === $this->basePath || str_starts_with($path, $this->basePath.'/'))) {
            $path = '/'.ltrim(substr($path, strlen($this->basePath)), '/');
        }

        return $path;
    }

    /** Ask Booklet for a page as a visitor would. */
    protected function fetch(string $path): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($path, 'GET', [], [], [], [
            'HTTP_HOST' => (string) parse_url($this->base, PHP_URL_HOST),
            'HTTPS' => str_starts_with($this->base, 'https') ? 'on' : 'off',
            'HTTP_USER_AGENT' => 'Booklet static publisher',
        ]);
        $request->attributes->set('booklet_static', true);

        return app(Kernel::class)->handle($request);
    }

    /** One page (and, for paged lists, its page 2, 3…). Returns false when it isn't there. */
    protected function page(string $path, ?string $file = null, int $pageNo = 1): bool
    {
        $response = $this->fetch($path.($pageNo > 1 ? '?page='.$pageNo : ''));
        if ($response->getStatusCode() !== 200) {
            return false;
        }
        $html = (string) $response->getContent();
        $file ??= $this->fileFor($path, $pageNo);
        $this->write($file, $this->rewrite($html, $path));

        // Paged lists: follow "Turn the page" while there is one.
        if (str_contains($html, '?page='.($pageNo + 1)) && $pageNo < 500) {
            $this->page($path, null, $pageNo + 1);
        }

        return true;
    }

    protected function fileFor(string $path, int $pageNo = 1): string
    {
        $folder = trim($path, '/');
        if ($pageNo > 1) {
            $folder = ltrim($folder.'/page/'.$pageNo, '/');
        }

        return ($folder === '' ? '' : $folder.'/').'index.html';
    }

    protected function notFound(): void
    {
        $response = $this->fetch('/'.Str::random(24).'-not-here');
        $this->write('404.html', $this->rewrite((string) $response->getContent(), '/'));
    }

    protected function plain(string $path): void
    {
        $response = $this->fetch($path);
        if ($response->getStatusCode() === 200) {
            $this->write(ltrim($path, '/'), $this->retarget((string) $response->getContent()));
        }
    }

    /** What search looks through, in the browser. */
    protected function searchIndex(): void
    {
        $site = app(Site::class);
        $before = app()->getLocale();
        app()->setLocale($site->language());
        $index = $site->published()->map(fn (Entry $e) => [
            't' => $e->title(),
            'u' => $site->url($e),
            'k' => $e->collection()?->dated() ? __('Post') : __('Page'),
            'p' => $e->collection()?->dated() ? 1 : 0,
            'd' => $e->date()?->translatedFormat('j F Y') ?? '',
            'x' => $site->excerpt($e, 160),
            's' => Str::limit(Str::lower(Members::locked($e) ? app(\App\Booklet\Site::class)->excerpt($e) // members-only: never the rest
                : (app(\App\PageBuilder\PageBuilder::class)->readerText($e) ?? strip_tags((string) Str::markdown((string) $e->get('content', '')))).' '.Blocks::textOf($e)), 4000, ''),
        ])->values()->all();
        app()->setLocale($before);
        $this->write('search-index.json', (string) json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Addresses in a page, made right for the static host: paged lists become
     * folders, links to the old address become the published one, and every
     * local picture, style and script is noted to copy.
     */
    protected function rewrite(string $html, string $from): string
    {
        $html = $this->retarget($html);
        $base = preg_quote($this->base, '#');

        // ?page=N on our own lists → /page/N/ (page 1 → the list itself).
        $html = (string) preg_replace_callback('#(href=")('.$base.'[^"?]*)\?page=(\d+)"#', function ($m) {
            $path = rtrim($m[2], '/');

            return $m[1].((int) $m[3] <= 1 ? $path.'/' : $path.'/page/'.$m[3].'/').'"';
        }, $html);

        // Local files the page uses: src, srcset, <link href>, inline url(…).
        preg_match_all('#(?:src|href)="('.$base.'/[^"]+\.(?:css|js|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|mp4|webm|pdf)(?:\?[^"]*)?)"#i', $html, $m);
        foreach ($m[1] as $url) {
            $this->note($url);
        }
        preg_match_all('#srcset="([^"]+)"#i', $html, $sets);
        foreach ($sets[1] as $set) {
            foreach (explode(',', $set) as $candidate) {
                $url = trim(explode(' ', trim($candidate))[0]);
                if (str_starts_with($url, $this->base.'/')) {
                    $this->note($url);
                }
            }
        }
        preg_match_all('#url\((["\']?)('.$base.'/[^)"\']+)\1\)#i', $html, $urls);
        foreach ($urls[2] as $url) {
            $this->note($url);
        }

        return $html;
    }

    /** Old addresses → the published one ("http://localhost/storage/…" → "https://you.github.io/site/storage/…"). */
    protected function retarget(string $text): string
    {
        foreach ($this->oldRoots as $old) {
            $text = (string) preg_replace('~'.preg_quote($old, '~').'(?=[/"\'\s<)?#]|$)~', $this->base, $text);
        }

        return $text;
    }

    protected function note(string $url): void
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath.'/')) {
            $path = substr($path, strlen($this->basePath));
        }
        $this->files[$path] = true;
    }

    /** Copy (or have Booklet make) every file the pages use. */
    protected function copyFiles(): void
    {
        $done = [];
        $queue = array_keys($this->files);
        while (($path = array_shift($queue)) !== null) {
            if (isset($done[$path]) || str_contains($path, '..')) {
                continue;
            }
            $done[$path] = true;
            $disk = public_path(ltrim($path, '/'));
            $body = null;
            if (is_file($disk)) {
                $body = (string) file_get_contents($disk);
            } else {
                // Not made yet (a share card, a sized or cut picture): Booklet makes it on request.
                $response = $this->fetch($path);
                if ($response->getStatusCode() === 200) {
                    $body = $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
                        ? (string) file_get_contents($response->getFile()->getPathname())
                        : (string) $response->getContent();
                }
            }
            if ($body === null) {
                continue;
            }
            // Stylesheets can point at fonts and pictures of their own.
            if (str_ends_with($path, '.css')) {
                preg_match_all('#url\((["\']?)([^)"\']+)\1\)#i', $body, $found);
                foreach ($found[2] as $ref) {
                    if (str_starts_with($ref, 'data:') || preg_match('#^https?://#', $ref) && ! str_starts_with($ref, $this->base)) {
                        continue;
                    }
                    $refPath = str_starts_with($ref, $this->base) ? substr($ref, strlen($this->base)) : (str_starts_with($ref, '/') ? $ref : dirname($path).'/'.$ref);
                    $queue[] = '/'.ltrim((string) parse_url($refPath, PHP_URL_PATH), '/');
                }
            }
            $this->write(ltrim($path, '/'), $body);
        }
    }

    protected function write(string $relative, string $content): void
    {
        $relative = ltrim($relative, '/');
        $target = $this->out.'/'.$relative;
        File::ensureDirectoryExists(dirname($target));
        file_put_contents($target, $content);
        $this->written[$relative] = true;
    }

    // Sending -------------------------------------------------------------------------

    /** The last build as a .zip, for Netlify Drop or any host. */
    public static function zip(): string
    {
        $folder = self::folder();
        if (! is_dir($folder)) {
            throw new RuntimeException('Make the files first.');
        }
        $zipPath = self::privatePath('static-site.zip');
        @unlink($zipPath);
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Couldn’t make the zip.');
        }
        foreach (File::allFiles($folder, true) as $file) {
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * Send the last build to the connected GitHub repository's "gh-pages"
     * branch (what GitHub Pages serves). Each send replaces the branch with
     * one fresh commit, so it never grows. Your own branches aren't touched.
     */
    public static function sendToGithub(): void
    {
        $folder = self::folder();
        if (! is_dir($folder)) {
            throw new RuntimeException('Make the files first.');
        }
        $remote = VersionControl::remoteUrl();
        if (! $remote) {
            throw new RuntimeException('Connect version control to GitHub first (Settings › Version control › A copy on GitHub).');
        }

        File::deleteDirectory($folder.'/.git');
        $git = fn (array $args, int $timeout = 60) => VersionControl::git($args, null, $timeout, $folder);
        foreach ([['init', '-q'], ['checkout', '-q', '-b', 'gh-pages'], ['add', '-A']] as $step) {
            $result = $git($step);
            if (! $result->successful()) {
                throw new RuntimeException('Git couldn’t get the files ready: '.Str::limit(trim($result->errorOutput()), 200));
            }
        }
        $commit = $git(['commit', '-q', '--no-verify', '--no-gpg-sign', '-m', 'The site, published by Booklet '.now()->format('j M Y, H:i')]);
        if (! $commit->successful()) {
            throw new RuntimeException('Git couldn’t commit the files: '.Str::limit(trim($commit->errorOutput() ?: $commit->output()), 200));
        }
        $push = $git(['push', '--force', $remote, 'gh-pages:gh-pages'], 180);
        File::deleteDirectory($folder.'/.git');
        if (! $push->successful()) {
            [, $words] = VersionControl::explain($push->errorOutput());
            Cache::forever('booklet.static.last', array_merge(self::last(), ['sent' => null, 'error' => $words]));
            throw new RuntimeException($words);
        }
        Cache::forever('booklet.static.last', array_merge(self::last(), ['sent' => now()->toIso8601String(), 'error' => null]));
    }

    /** GitHub's page for switching Pages on, for the connected repository. */
    public static function githubPagesSettings(): ?string
    {
        $web = VersionControl::webUrl();

        return $web && str_starts_with($web, 'https://github.com/') ? $web.'/settings/pages' : null;
    }
}
