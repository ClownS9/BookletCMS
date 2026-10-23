<?php

namespace App\Themes;

/**
 * One theme in themes/{key}/: a theme.json, its files in assets/ (styles,
 * fonts, pictures, and for a theme with code, scripts), and, for a theme
 * with code, templates in views/ that stand in for Booklet's own.
 *
 * A theme without views/ or scripts is a "cover pack": safe to add from
 * anyone, because it can only change how the site looks.
 */
class Theme
{
    /** Folders under views/ a theme may put templates in: the public site's, and nothing of the admin. */
    public const VIEW_FOLDERS = ['site', 'components/site', 'errors'];

    /** @var list<string> */
    public array $problems = [];

    public function __construct(
        public readonly string $key,
        public readonly string $path,
        protected array $manifest,
    ) {}

    public static function load(string $path): ?self
    {
        $file = $path.'/theme.json';
        if (! is_file($file)) {
            return null;
        }
        ['manifest' => $manifest, 'problems' => $problems] = ThemeManifest::read((string) file_get_contents($file), basename($path));
        if (($manifest['key'] ?? '') !== basename($path)) {
            // The folder's name is the key (a theme.json saying otherwise was copied from another).
            $manifest['key'] = basename($path);
        }
        if (! preg_match(ThemeManifest::KEY, $manifest['key']) || $manifest['key'] === 'booklet') {
            return null; // "booklet" is Booklet's own cover
        }
        $theme = new self($manifest['key'], $path, $manifest);
        $theme->problems = $problems;

        return $theme;
    }

    public function name(): string
    {
        return (string) $this->manifest['name'];
    }

    public function about(): string
    {
        return (string) $this->manifest['about'];
    }

    public function version(): string
    {
        return (string) $this->manifest['version'];
    }

    public function author(): ?string
    {
        return $this->manifest['author'];
    }

    public function homepage(): ?string
    {
        return $this->manifest['homepage'];
    }

    public function needs(): ?string
    {
        return $this->manifest['needs'];
    }

    public function fits(): bool
    {
        return ! $this->needs() || version_compare((string) config('booklet.version', '1.0'), (string) $this->needs(), '>=');
    }

    /** Templates or scripts: code that runs. Without either, it's a cover pack. */
    public function hasCode(): bool
    {
        return $this->templates() !== [] || $this->manifest['js'] !== [];
    }

    /** In safe mode a theme is worn as a plain cover pack: its styles, nothing that runs. */
    public function codeOn(): bool
    {
        return $this->hasCode() && ! config('booklet.safe_mode', false);
    }

    public function usesBookletCss(): bool
    {
        // Without its own templates, a theme dresses Booklet's, which need Booklet's styles.
        return $this->manifest['booklet_css'] || ! $this->codeOn() || $this->templates() === [];
    }

    /** @return list<string> */
    public function css(): array
    {
        return array_values(array_filter($this->manifest['css'], fn ($f) => is_file($this->path.'/assets/'.$f)));
    }

    /** @return list<string> */
    public function js(): array
    {
        return $this->codeOn() ? array_values(array_filter($this->manifest['js'], fn ($f) => is_file($this->path.'/assets/'.$f))) : [];
    }

    /** handle => [name, google family or null, CSS stack, uses] */
    public function fonts(): array
    {
        return $this->manifest['fonts'];
    }

    /** handle => [name, paper, paper-2, rule, rule-2, ink] */
    public function papers(): array
    {
        return $this->manifest['papers'];
    }

    public function presets(): array
    {
        $presets = array_map(fn ($p) => ThemeManifest::fillPreset($p), $this->manifest['presets']);

        // Every theme has at least one way to start.
        return $presets ?: ['default' => ThemeManifest::fillPreset(['name' => $this->name(), 'about' => $this->about()])];
    }

    public function options(): array
    {
        return $this->manifest['options'];
    }

    public function previewUrl(): ?string
    {
        $file = $this->manifest['preview'];

        return $file && is_file($this->path.'/assets/'.$file) ? $this->asset($file) : null;
    }

    /** The web address of one of its files (themes/{key}/assets/…). */
    public function asset(string $file): string
    {
        $file = ltrim($file, '/');
        $full = $this->path.'/assets/'.$file;

        return route('themes.asset', ['theme' => $this->key, 'path' => $file]).(is_file($full) ? '?v='.filemtime($full) : '');
    }

    public function viewsPath(): string
    {
        return $this->path.'/views';
    }

    /**
     * The templates it brings, as view names ("site.post", "components.site.layout").
     *
     * @return list<string>
     */
    public function templates(): array
    {
        $root = $this->viewsPath();
        if (! is_dir($root)) {
            return [];
        }
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_ends_with($rel, '.blade.php')) {
                $found[] = str_replace('/', '.', substr($rel, 0, -10));
            }
        }
        sort($found);

        return $found;
    }

    /** Templates somewhere a theme mustn't put them (anything but the public site's). */
    public function strayTemplates(): array
    {
        return array_values(array_filter($this->templates(), function (string $view) {
            foreach (self::VIEW_FOLDERS as $folder) {
                if (str_starts_with($view, str_replace('/', '.', $folder).'.')) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function manifest(): array
    {
        return $this->manifest;
    }
}
