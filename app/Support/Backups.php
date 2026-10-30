<?php

namespace App\Support;

use App\Booklet\Facades\Booklet;
use App\Booklet\Schema\Definition;
use App\Booklet\Schema\Definitions;
use App\Booklet\Stores\StoreFactory;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Backups: the whole book in one .zip, and back again.
 *
 * Inside a backup:
 *   manifest.json         what's in it, when, from which site
 *   content/{name}.json   every Booklet item (entries, pages tree, menus, settings, file details…)
 *   tables/{name}.json    people, comments, redirects, sticky notes, dog-ears
 *   files/{disk}/{path}   the media files themselves, and people's pictures
 *
 * Left out on purpose: AI service keys (they stay on this server), Stacy's
 * conversations, visit stats, the activity log and the bin.
 *
 * Kept in storage/app/private/backups. Nightly ones keep the newest 7.
 */
class Backups
{
    public const FORMAT = 'booklet-backup';

    public const VERSION = 1;

    public const DIR = 'backups';

    public const KEEP_NIGHTLY = 7;

    /** App tables that belong in a backup, with the columns that hold user ids. */
    public const TABLES = [
        'users' => [],
        'comments' => ['user_id'],
        'redirects' => ['created_by'],
        'entry_notes' => ['user_id', 'done_by'],
        'dog_ears' => ['user_id'],
        'subscribers' => [],
        'readers' => ['invited_by'],  // accounts on the site (their passwords stay hashed)
        'groups' => [],
        'group_members' => [],        // a member who is one of the people: mapped like the columns above
        'post_emails' => [],
        'inserts' => [],          // which inserts are on, and their settings (their code is in inserts/)
        'insert_records' => [],   // what inserts keep: albums, events…
    ];

    protected static function disk(): \Illuminate\Filesystem\FilesystemAdapter
    {
        return \App\Support\Disk::of('local');
    }

    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    // Making one ------------------------------------------------------------------

    /**
     * @param  string  $kind  manual · nightly · before-restore
     * @return array{name: string, size: int}
     */
    public static function create(string $kind = 'manual', ?User $by = null): array
    {
        throw_unless(self::available(), RuntimeException::class, 'This server can’t make .zip files (PHP’s zip extension is missing).');
        @set_time_limit(0);

        $by ??= request()->user();
        $site = rescue(fn () => app(\App\Booklet\Site::class)->name(), config('app.name'), false);
        $name = now()->format('Y-m-d-His').'-'.Str::slug($site ?: 'booklet').'-'.$kind.'.zip';
        self::disk()->makeDirectory(self::DIR);
        $path = self::disk()->path(self::DIR.'/'.$name);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Couldn’t start the backup file.');
        }

        $counts = ['content' => [], 'tables' => [], 'files' => 0];

        // 1. Everything Booklet keeps, whichever driver it's in.
        foreach (Definitions::all() as $definition) {
            $records = array_map(
                fn ($r) => ['attributes' => $r['attributes'], 'data' => $r['data'] ?? []],
                StoreFactory::make($definition)->all(),
            );
            $zip->addFromString('content/'.$definition->name.'.json', json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $counts['content'][$definition->name] = count($records);
        }

        // 2. The app's own tables.
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            if ($table === 'users') {
                $rows = array_map(fn ($r) => array_diff_key($r, ['remember_token' => 1]), $rows);
            }
            if ($table === 'inserts') {
                // Inserts' secret settings (another service's keys) never go in a backup, like AI keys.
                $rows = array_map(function ($r) {
                    $settings = json_decode((string) ($r['settings'] ?? ''), true);
                    if (is_array($settings)) {
                        $r['settings'] = json_encode(array_map(fn ($v) => is_string($v) && str_starts_with($v, \App\Inserts\Insert::SECRET) ? '' : $v, $settings));
                    }

                    return $r;
                }, $rows);
            }
            $zip->addFromString('tables/'.$table.'.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $counts['tables'][$table] = count($rows);
        }

        // 3. The files: media in each container, and people's pictures.
        foreach (self::files() as [$diskName, $file]) {
            $source = \App\Support\Disk::of($diskName);
            if (! $source->exists($file)) {
                continue;
            }
            $entry = 'files/'.$diskName.'/'.ltrim($file, '/');
            $local = rescue(fn () => $source->path($file), null, false);
            $local && is_file($local) ? $zip->addFile($local, $entry) : $zip->addFromString($entry, (string) $source->get($file));
            $counts['files']++;
        }

        $zip->addFromString('manifest.json', json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'kind' => $kind,
            'created_at' => now()->toIso8601String(),
            'site' => $site,
            'url' => config('app.url'),
            'by' => $by?->name,
            'counts' => $counts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $zip->close();

        if ($kind !== 'nightly') {
            ActivityLog::record('made a backup', 'settings', 'backup', $name, $name);
        }

        return ['name' => $name, 'size' => (int) filesize($path)];
    }

    /** [disk, path] of every file a backup should carry. */
    protected static function files(): Collection
    {
        $media = rescue(fn () => Booklet::assets()->all()->map(function ($asset) {
            $disk = Booklet::assetContainers()->find($asset->container())?->attribute('disk') ?? 'public';

            return [$disk, $asset->path()];
        }), collect(), false);

        $avatars = rescue(fn () => User::query()->whereNotNull('avatar')->pluck('avatar')->map(fn ($p) => ['public', $p]), collect(), false);

        return $media->merge($avatars)->unique(fn ($f) => $f[0].':'.$f[1])->values();
    }

    // The ones we have ------------------------------------------------------------

    /**
     * @return Collection<int, array{name: string, size: int, at: Carbon, kind: string}>
     */
    public static function list(): Collection
    {
        return collect(self::disk()->files(self::DIR))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->map(fn ($f) => [
                'name' => basename($f),
                'size' => (int) self::disk()->size($f),
                // When it was made is in its name (file dates change when files are copied about).
                'at' => preg_match('/^(\d{4}-\d{2}-\d{2}-\d{6})-/', basename($f), $d)
                    ? (Carbon::createFromFormat('Y-m-d-His', $d[1]) ?: Carbon::createFromTimestamp(self::disk()->lastModified($f)))
                    : Carbon::createFromTimestamp(self::disk()->lastModified($f)),
                'kind' => preg_match('/-(manual|nightly|before-restore|uploaded)\.zip$/', $f, $m) ? $m[1] : 'manual',
            ])
            ->sortByDesc('at')
            ->values();
    }

    /** The full path of a stored backup, refusing anything that isn't one. */
    public static function path(string $name): string
    {
        $name = basename($name);
        abort_unless(str_ends_with($name, '.zip') && self::disk()->exists(self::DIR.'/'.$name), 404);

        return self::disk()->path(self::DIR.'/'.$name);
    }

    public static function delete(string $name): void
    {
        self::path($name);
        self::disk()->delete(self::DIR.'/'.basename($name));
    }

    /** Keep an uploaded backup alongside ours (after checking it is one). */
    public static function keepUpload(string $tempPath): string
    {
        $manifest = self::manifest($tempPath);
        $name = now()->format('Y-m-d-His').'-'.Str::slug($manifest['site'] ?? 'booklet').'-uploaded.zip';
        self::disk()->makeDirectory(self::DIR);
        copy($tempPath, self::disk()->path(self::DIR.'/'.$name));

        return $name;
    }

    /** Nightly: make one, keep the newest seven nightly backups. */
    public static function nightly(): ?string
    {
        if (! self::available() || ! (bool) rescue(fn () => app(\App\Booklet\Site::class)->setting('backups_nightly', true), true, false)) {
            return null;
        }

        $made = self::create('nightly')['name'];
        self::list()->where('kind', 'nightly')->slice(self::KEEP_NIGHTLY)->each(fn ($b) => self::delete($b['name']));

        return $made;
    }

    // Reading one back ------------------------------------------------------------

    /** What's in a backup (and that it really is one). */
    public static function manifest(string $zipPath): array
    {
        throw_unless(self::available(), RuntimeException::class, 'This server can’t open .zip files (PHP’s zip extension is missing).');

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('That isn’t a .zip file Booklet can open.');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('That .zip isn’t a Booklet backup.');
        }
        if ((int) ($manifest['version'] ?? 0) > self::VERSION) {
            throw new RuntimeException('That backup was made by a newer Booklet. Update this site first.');
        }

        return $manifest;
    }

    /**
     * Replace this book with the backup. A safety backup is made first.
     * People are matched by email (so you stay signed in), and entries keep
     * their authors.
     *
     * @return array{safety: string, counts: array}
     */
    public static function restore(string $zipPath, ?User $by = null): array
    {
        $manifest = self::manifest($zipPath);
        @set_time_limit(0);
        $by ??= request()->user();

        $safety = self::create('before-restore', $by)['name'];

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $read = fn (string $entry) => json_decode((string) $zip->getFromName($entry), true);
        $counts = [];

        // 1. People first: match by email, so ids can be translated everywhere else.
        $ids = self::restoreUsers((array) ($read('tables/users.json') ?? []));
        $counts['people'] = count($ids);
        $map = fn ($id) => $id === null ? null : ($ids[(string) $id] ?? null);

        // 2. Booklet content: each kind replaced wholesale.
        foreach (Definitions::all() as $definition) {
            $records = $read('content/'.$definition->name.'.json');
            if (! is_array($records)) {
                continue; // not in this backup: left as it is
            }
            $store = StoreFactory::make($definition);
            $store->truncate();
            foreach ($records as $record) {
                $data = (array) ($record['data'] ?? []);
                if ($definition->name === 'entries' && isset($data['author'])) {
                    $data['author'] = $map($data['author']) ?? $by?->id;
                }
                $store->save($definition->castAttributes((array) $record['attributes']), $data);
            }
            $counts[$definition->name] = count($records);
        }
        Booklet::flush();

        // 3. Tables (people done already). Rows go back in id order, so replies follow their comments.
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table => $userColumns) {
            $rows = $read('tables/'.$table.'.json');
            if ($table === 'users' || ! is_array($rows) || ! Schema::hasTable($table)) {
                continue;
            }
            DB::transaction(function () use ($table, $rows, $userColumns, $map, &$counts) {
                DB::table($table)->delete();
                $columns = Schema::getColumnListing($table);
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table($table)->insert(array_values(array_filter(array_map(function ($row) use ($userColumns, $map, $columns, $table) {
                        foreach ($userColumns as $col) {
                            if (array_key_exists($col, $row)) {
                                $row[$col] = $map($row[$col]);
                            }
                        }
                        if ($table === 'dog_ears' && ! $row['user_id']) {
                            return null; // a bookmark for someone who isn't here
                        }
                        if ($table === 'group_members' && ($row['member_type'] ?? '') === 'user' && ! ($row['member_id'] = $map($row['member_id']))) {
                            return null; // someone from the book who isn't here
                        }

                        return array_intersect_key($row, array_flip($columns));
                    }, $chunk))));
                }
                $counts[$table] = count($rows);
            });
        }
        Schema::enableForeignKeyConstraints();

        // 4. Files.
        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (! str_starts_with($entry, 'files/') || str_ends_with($entry, '/') || str_contains($entry, '..')) {
                continue;
            }
            [, $diskName, $file] = explode('/', $entry, 3) + [null, null, null];
            if (! $diskName || ! $file || ! array_key_exists($diskName, (array) config('filesystems.disks'))) {
                continue;
            }
            $stream = $zip->getStream($entry);
            if ($stream) {
                \App\Support\Disk::of($diskName)->writeStream($file, $stream);
                is_resource($stream) && fclose($stream);
                $files++;
            }
        }
        $counts['files'] = $files;
        $zip->close();

        ActivityLog::record('restored a backup from', 'settings', 'backup', basename($zipPath), Carbon::parse($manifest['created_at'])->format('j M Y, g:ia'));

        return ['safety' => $safety, 'counts' => $counts];
    }

    /**
     * Bring people back: anyone with the same email is the same person (their
     * password and role here are kept); anyone missing is added with the
     * password they had. Returns old id => id here.
     */
    protected static function restoreUsers(array $rows): array
    {
        $ids = [];
        $columns = Schema::getColumnListing('users');

        foreach ($rows as $row) {
            if (empty($row['email'])) {
                continue;
            }
            $existing = User::where('email', $row['email'])->first();
            if ($existing) {
                $ids[(string) $row['id']] = $existing->id;

                continue;
            }
            $values = array_intersect_key($row, array_flip($columns));
            unset($values['id']);
            $values['remember_token'] = null;
            // Their two-step secret may be locked with another site's key: they set it up again.
            unset($values['two_factor_secret'], $values['two_factor_recovery_codes'], $values['two_factor_confirmed_at']);
            $ids[(string) $row['id']] = DB::table('users')->insertGetId($values);
        }

        return $ids;
    }
}
