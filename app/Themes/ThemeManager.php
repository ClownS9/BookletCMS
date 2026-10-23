<?php

namespace App\Themes;

use App\Support\Look;
use App\Support\StaticSite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The themes on the shelf (themes/), the one the site wears (chosen in
 * Settings › The cover, or the one being previewed there), and putting a
 * theme's templates in front of Booklet's own while a page is made.
 *
 * When a theme's template breaks, the page is made again with Booklet's
 * own (see ApplyTheme), and what went wrong is kept to show in The cover
 * and the Check-up.
 */
class ThemeManager
{
    /** @var Collection<string, Theme>|null */
    protected static ?Collection $all = null;

    protected static ?string $allFrom = null;

    /** Booklet's own view folders, before any theme was put in front. */
    protected static ?array $paths = null;

    protected static ?string $applied = null;

    /** Which view finder those were for (a fresh app, in tests or a queue worker, starts again). */
    protected static ?int $finder = null;

    public static function path(): string
    {
        return rtrim((string) config('booklet.themes_path', base_path('themes')), '/');
    }

    /** @return Collection<string, Theme> every theme on the shelf, by key */
    public static function all(): Collection
    {
        if (self::$all !== null && self::$allFrom === self::path() && self::$all->every(fn (Theme $t) => is_dir($t->path))) {
            return self::$all;
        }
        self::$allFrom = self::path();
        $themes = collect(glob(self::path().'/*', GLOB_ONLYDIR) ?: [])
            ->map(fn ($dir) => Theme::load($dir))
            ->filter()
            ->sortBy(fn (Theme $t) => mb_strtolower($t->name()))
            ->mapWithKeys(fn (Theme $t) => [$t->key => $t]);

        return self::$all = $themes;
    }

    public static function find(?string $key): ?Theme
    {
        return $key ? self::all()->get($key) : null;
    }

    public static function forget(): void
    {
        self::$all = null;
    }

    /** The theme a look wears (null: Booklet's own). */
    public static function forLook(array $look): ?Theme
    {
        $theme = is_string($look['theme'] ?? null) ? self::find($look['theme']) : null;

        return $theme && $theme->fits() ? $theme : null;
    }

    public static function current(): ?Theme
    {
        return self::forLook(Look::current());
    }

    // Templates --------------------------------------------------------------------------

    /**
     * Put a theme's templates in front of Booklet's (or take them away: null).
     * Only a theme with code, outside safe mode, and only templates of the public site.
     */
    public static function apply(?Theme $theme): void
    {
        $finder = app('view')->getFinder();
        if (! $finder instanceof \Illuminate\View\FileViewFinder) {
            return;
        }
        if (self::$finder !== spl_object_id($finder)) {
            self::$finder = spl_object_id($finder);
            self::$paths = $finder->getPaths();
            self::$applied = null;
            self::$all = null;
        }

        $want = $theme && $theme->codeOn() && $theme->strayTemplates() === [] && is_dir($theme->viewsPath()) ? $theme->key : null;
        if ($want === self::$applied && ($want !== null || $finder->getPaths() === self::$paths)) {
            return;
        }

        $finder->setPaths($want ? [$theme->viewsPath(), ...self::$paths] : self::$paths);
        $finder->flush();
        self::$applied = $want;
    }

    public static function applied(): ?string
    {
        return self::$applied;
    }

    /** Did this go wrong in one of a theme's templates? */
    public static function causedBy(Theme $theme, Throwable $e): bool
    {
        $root = realpath($theme->path) ?: $theme->path;
        for ($at = $e, $depth = 0; $at && $depth < 8; $at = $at->getPrevious(), $depth++) {
            if (str_contains($at->getMessage(), $root.DIRECTORY_SEPARATOR) || str_starts_with($at->getFile(), $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A theme's @font-face rules (from its styles), with their files' full
     * addresses: so The cover can show its fonts in the admin, without the
     * rest of its styles.
     */
    public static function fontFaces(Theme $theme): string
    {
        $out = '';
        foreach ($theme->css() as $file) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) @file_get_contents($theme->path.'/assets/'.$file));
            preg_match_all('/@font-face\s*\{[^}]*\}/i', $css, $m);
            $dir = trim(dirname($file), '.');
            foreach ($m[0] as $rule) {
                $rule = (string) preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($u) use ($theme, $dir) {
                    $url = trim($u[2]);
                    if (preg_match('#^[a-z][a-z0-9+.-]*:|^/#i', $url)) {
                        return 'url(about:blank)'; // only its own files
                    }
                    $path = ltrim(($dir !== '' ? $dir.'/' : '').$url, '/');

                    return 'url("'.$theme->asset(str_replace('/./', '/', $path)).'")';
                }, $rule);
                $out .= str_ireplace('</style', '', $rule)."\n";
            }
        }

        return $out;
    }

    // What went wrong --------------------------------------------------------------------

    protected static function errorsFile(): string
    {
        return StaticSite::privatePath('theme-errors.json');
    }

    public static function noteError(Theme $theme, Throwable $e, string $where): void
    {
        $errors = self::errors();
        $root = realpath($theme->path) ?: $theme->path;
        $message = preg_replace('/\s*\(View: [^)]*\)$/', '', $e->getMessage()) ?? $e->getMessage();
        $file = null;
        if (preg_match('/\(View: ([^)]+)\)/', $e->getMessage(), $m)) {
            $file = $m[1];
        } elseif (str_starts_with($e->getFile(), $root)) {
            $file = $e->getFile();
        }
        $errors[$theme->key] = [
            'message' => mb_substr(trim($message), 0, 400),
            'file' => $file ? ltrim(str_replace($root, '', $file), '/') : null,
            'where' => '/'.ltrim($where, '/'),
            'at' => now()->toIso8601String(),
            'version' => $theme->version(),
            'count' => (int) (($errors[$theme->key]['count'] ?? 0)) + 1,
        ];
        rescue(function () use ($errors) {
            File::ensureDirectoryExists(dirname(self::errorsFile()));
            file_put_contents(self::errorsFile(), json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }, null, false);
    }

    public static function errors(): array
    {
        $errors = json_decode((string) @file_get_contents(self::errorsFile()), true);

        return is_array($errors) ? $errors : [];
    }

    /** What last went wrong with a theme (forgotten once it's updated). */
    public static function error(Theme $theme): ?array
    {
        $error = self::errors()[$theme->key] ?? null;

        return $error && ($error['version'] ?? null) === $theme->version() ? $error : null;
    }

    public static function clearError(string $key): void
    {
        $errors = self::errors();
        if (isset($errors[$key])) {
            unset($errors[$key]);
            @file_put_contents(self::errorsFile(), json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}
