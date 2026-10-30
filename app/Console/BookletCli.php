<?php

namespace App\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * The `php booklet` front door: Booklet's commands without their prefix
 * (`php booklet backup` runs booklet:backup), a grouped list when nothing is
 * asked for, and anything else passed on to Laravel as php artisan would.
 */
class BookletCli
{
    /** Turn what was typed into what Laravel runs. */
    public static function input(Application $app, array $argv): ArgvInput
    {
        $tokens = array_slice($argv, 1);
        $app->make(Kernel::class)->bootstrap();
        $known = array_keys($app->make(Kernel::class)->all());

        // The first word that isn't an option is the command.
        $at = null;
        foreach ($tokens as $i => $token) {
            if ($token === '--') {
                break;
            }
            if (! str_starts_with($token, '-')) {
                $at = $i;
                break;
            }
        }

        if ($at === null) {
            // Nothing asked for (or only --version and the like): Booklet's own list.
            $wantsVersion = array_intersect($tokens, ['-V', '--version']) !== [];
            if (! $wantsVersion) {
                array_unshift($tokens, 'booklet:list');
            }

            return new ArgvInput(array_merge([$argv[0]], $tokens));
        }

        $command = $tokens[$at];
        if ($command === 'list') {
            // `php booklet list` is ours; `php booklet list --all` is Laravel's whole list.
            $all = array_search('--all', $tokens, true);
            if ($all !== false) {
                unset($tokens[$all]);
            } else {
                $tokens[$at] = 'booklet:list';
            }
        } elseif ($command === 'help' && isset($tokens[$at + 1])) {
            $tokens[$at + 1] = self::name($tokens[$at + 1], $known);
        } else {
            $tokens[$at] = self::name($command, $known);
        }

        return new ArgvInput(array_merge([$argv[0]], array_values($tokens)));
    }

    /** `php booklet --version` on its own: Booklet's version, then Laravel's. */
    public static function version(Application $app, array $argv): ?string
    {
        $tokens = array_slice($argv, 1);
        if ($tokens === [] || array_diff($tokens, ['-V', '--version', '--no-ansi', '--ansi']) !== [] || array_intersect($tokens, ['-V', '--version']) === []) {
            return null;
        }
        $app->make(Kernel::class)->bootstrap();

        return 'Booklet '.config('booklet.version', '').' (Laravel '.$app->version().', PHP '.PHP_VERSION.')';
    }

    /** "backup" → "booklet:backup" when there's one; anything else as typed. */
    public static function name(string $typed, array $known): string
    {
        if (str_starts_with($typed, 'booklet:')) {
            return $typed;
        }

        return in_array('booklet:'.$typed, $known, true) ? 'booklet:'.$typed : $typed;
    }
}
