<?php

namespace App\Support;

use App\Inserts\InsertInstaller;
use App\Inserts\InsertManager;
use App\Themes\ThemeInstaller;
use App\Themes\ThemeManager;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * Sharing a theme or an insert with other Booklet sites: packed into a
 * .zip, checked the same way Booklet checks one being added (so what's
 * sent is what others can add), and what to call it on GitHub so people
 * can add it by name, get updates, and find it. Used by the "Share it"
 * guide in The cover and on the Inserts shelf, and by booklet:share.
 */
class Sharing
{
    /** The GitHub topics that mark a repository as one of these. */
    public const TOPICS = ['theme' => 'booklet-theme', 'insert' => 'booklet-insert'];

    /**
     * @return array{kind: string, key: string, name: string, version: string, path: string, file: string, count: int, code: bool, problems: list<string>}
     *
     * @throws RuntimeException when there's no such one, or it wouldn't be accepted
     */
    public static function pack(string $key, ?string $kind = null, ?string $to = null): array
    {
        $theme = $kind !== 'insert' ? ThemeManager::find($key) : null;
        $insert = ! $theme && $kind !== 'theme' ? app(InsertManager::class)->find($key) : null;
        if (! $theme && ! $insert) {
            throw new RuntimeException("There's no ".($kind ?? 'theme or insert')." called {$key}.");
        }
        $kind = $theme ? 'theme' : 'insert';
        $folder = $theme ? $theme->path : $insert->path;
        $version = $theme ? $theme->version() : $insert->version();

        $to = rtrim($to ?: StaticSite::privatePath('share'), '/');
        File::ensureDirectoryExists($to);
        $file = 'booklet-'.$key.'-'.(preg_replace('/[^A-Za-z0-9.-]/', '', $version) ?: '1.0').'.zip';
        $path = $to.'/'.$file;

        // Everything in the folder at the top of the .zip, as a GitHub repository has it.
        @unlink($path);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("The .zip couldn't be made in {$to}.");
        }
        $count = 0;
        foreach (Finder::create()->files()->in($folder)->ignoreDotFiles(true)->ignoreVCS(true)->exclude(['node_modules']) as $found) {
            $zip->addFile($found->getPathname(), str_replace('\\', '/', $found->getRelativePathname()));
            $count++;
        }
        $zip->close();

        // Checked as if someone were adding it.
        $installer = $theme ? app(ThemeInstaller::class) : app(InsertInstaller::class);
        try {
            $token = $installer->stage($path, ['type' => 'upload'], $file);
            $meta = (array) $installer->staged($token);
            $installer->discard($token);
        } catch (RuntimeException $e) {
            @unlink($path);
            throw new RuntimeException('It wouldn’t be accepted by another site: '.$e->getMessage(), 0, $e);
        }

        return [
            'kind' => $kind, 'key' => $key, 'name' => $theme ? $theme->name() : $insert->name(), 'version' => $version,
            'path' => $path, 'file' => $file, 'count' => $count,
            'code' => $kind === 'insert' || ! empty($meta['code']),
            'problems' => array_values((array) ($meta['problems'] ?? [])),
        ];
    }

    public static function repository(string $key): string
    {
        return 'booklet-'.$key;
    }

    /** The GitHub account the book is connected to (Version control), to fill in the addresses. */
    public static function githubOwner(): ?string
    {
        $web = rescue(fn () => VersionControl::webUrl(), null, false);

        return is_string($web) && preg_match('#^https://github\.com/([A-Za-z0-9-]{1,39})/#', $web, $m) ? $m[1] : null;
    }

    /** github.com's "new repository" page, with the name and a description filled in. */
    public static function newRepositoryUrl(string $key, string $about): string
    {
        return 'https://github.com/new?'.http_build_query([
            'name' => self::repository($key),
            'description' => mb_substr($about, 0, 300),
            'visibility' => 'public',
        ]);
    }
}
