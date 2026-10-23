<?php

namespace App\Console\Commands;

use App\Themes\ThemeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * php artisan booklet:make-theme lighthouse
 * php artisan booklet:make-theme lighthouse --templates
 *
 * Makes a theme in themes/lighthouse/ that already works: a theme.json with
 * a preset, a font, a paper and an option, and a stylesheet to change. With
 * --templates, copies of Booklet's own templates to change as well (which
 * makes it a theme with code). Then pick it in Settings › The cover, and
 * zip the folder to share it.
 */
class BookletMakeTheme extends Command
{
    protected $signature = 'booklet:make-theme {name : A short name, like "lighthouse"}
        {--templates : Start from copies of Booklet’s templates (a theme with code)}';

    protected $description = 'Make a new theme (a cover pack, or one with its own templates) in themes/';

    /** The templates a theme usually starts from. */
    public const STARTERS = [
        'components/site/layout.blade.php',
        'site/blog.blade.php',
        'site/post.blade.php',
        'site/page.blade.php',
        'site/partials/card.blade.php',
    ];

    public function handle(): int
    {
        $key = Str::slug((string) $this->argument('name'));
        if (! preg_match('/^[a-z][a-z0-9-]{0,59}$/', $key) || $key === 'booklet') {
            $this->error('Use letters, numbers and dashes, starting with a letter (like lighthouse).');

            return self::FAILURE;
        }
        $dir = ThemeManager::path().'/'.$key;
        if (file_exists($dir)) {
            $this->error("There's already a theme at {$dir}.");

            return self::FAILURE;
        }
        $name = Str::headline($key);
        File::ensureDirectoryExists($dir.'/assets', 0755);

        $manifest = [
            'key' => $key,
            'name' => $name,
            'about' => 'A few words on how it looks.',
            'version' => '1.0',
            'author' => 'You',
            'needs' => (string) config('booklet.version', '1.0'),
            'css' => [$key.'.css'],
            'fonts' => [
                'display' => ['name' => 'Fraunces', 'google' => 'Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400', 'stack' => "'Fraunces', Georgia, serif", 'use' => ['titles']],
            ],
            'papers' => [
                'linen' => ['name' => 'Linen', 'paper' => '#F7F4EE', 'paper_2' => '#ECE7DD', 'rule' => '#E0D9CC', 'rule_2' => '#CBC2B1', 'ink' => '#1D1B17'],
            ],
            'options' => [
                'wide' => ['type' => 'toggle', 'label' => 'Wider pages', 'default' => false, 'class' => $key.'-wide'],
            ],
            'presets' => [
                'first' => ['name' => $name, 'about' => 'Where it starts.', 'title_font' => 'display', 'label_font' => 'inter', 'accent' => '#3B5BDB', 'paper' => 'linen', 'header' => 'classic', 'home' => 'grid', 'corners' => 'soft', 'tilt' => false],
            ],
        ];

        $css = <<<CSS
        /*
         * {$name}: styles for Booklet's public site. Every page's <body> has the
         * class .theme-{$key}, so start each rule with it. The cover's choices are
         * there to use: var(--accent), var(--paper), var(--ink), var(--serif),
         * var(--sans), and classes like .look-header--centered.
         */

        .theme-{$key} .post-head__title { letter-spacing: -0.015em; }

        /* The "Wider pages" option (theme.json › options › wide). */
        .theme-{$key}.{$key}-wide .wrap { max-width: 1280px; }

        CSS;

        if ($this->option('templates')) {
            foreach (self::STARTERS as $file) {
                File::ensureDirectoryExists(dirname($dir.'/views/'.$file), 0755);
                File::copy(resource_path('views/'.$file), $dir.'/views/'.$file);
            }
        }

        file_put_contents($dir.'/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        file_put_contents($dir.'/assets/'.$key.'.css', $css);
        file_put_contents($dir.'/README.md', "# {$name}\n\nA theme for Booklet. See themes/README.md for what each part of theme.json does.\n");
        ThemeManager::forget();

        $this->info("Made {$dir}.");
        $this->line($this->option('templates')
            ? '  Its templates are copies of Booklet’s, in views/: change them, and delete any you don’t need (Booklet uses its own for those).'
            : '  A cover pack: styles, fonts, colours and presets. Add --templates to start from Booklet’s templates too.');
        $this->line('  Pick it in Settings › The cover. To share it, zip the folder.');

        return self::SUCCESS;
    }
}
