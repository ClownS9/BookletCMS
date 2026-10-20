<?php

namespace App\Booklet\Schema;

use App\Booklet\Data;
use App\Booklet\Repositories;

/**
 * The full list of things Booklet stores, mirroring Statamic's repositories
 * and its eloquent-driver tables. File paths follow Statamic's layout.
 */
class Definitions
{
    /** @var array<string, Definition>|null */
    protected static ?array $definitions = null;

    /**
     * @return array<string, Definition>
     */
    public static function all(): array
    {
        return static::$definitions ??= static::build();
    }

    public static function get(string $name): Definition
    {
        return static::all()[$name]
            ?? throw new \InvalidArgumentException("Unknown Booklet repository [{$name}].");
    }

    /**
     * @return array<string, Definition>
     */
    public static function inGroup(string $group): array
    {
        return array_filter(static::all(), fn (Definition $d) => $d->group === $group);
    }

    /**
     * @return string[]
     */
    public static function groups(): array
    {
        return array_values(array_unique(array_map(fn (Definition $d) => $d->group, static::all())));
    }

    protected static function defaultSite(): string
    {
        return config('booklet.default_site', 'default');
    }

    /**
     * Split a relative path into its folders and its file name without extension.
     *
     * @return array{0: string[], 1: string}
     */
    protected static function split(string $path, string $extension): array
    {
        $segments = explode('/', trim($path, '/'));
        $file = array_pop($segments);
        $suffix = '.'.$extension;

        if (! str_ends_with($file, $suffix)) {
            return [[], ''];
        }

        return [$segments, substr($file, 0, -strlen($suffix))];
    }

    /**
     * A thing with a handle, stored as {dir}/{handle}.yaml with nothing below it.
     */
    protected static function handled(
        string $name, string $group, string $item, string $table, array $columns,
        string $json, string $root, string $dir, array $casts = [],
    ): Definition {
        return new Definition(
            name: $name,
            group: $group,
            item: $item,
            repository: Repositories\Repository::class,
            table: $table,
            columns: array_merge(['handle'], $columns),
            key: ['handle'],
            json: $json,
            casts: $casts,
            uuid: false,
            fileRoot: $root,
            fileDir: $dir,
            fileExtension: 'yaml',
            filePath: fn (array $a) => "{$a['handle']}.yaml",
            parsePath: function (string $path) {
                [$folders, $handle] = static::split($path, 'yaml');

                return $folders === [] && $handle !== '' ? ['handle' => $handle] : null;
            },
        );
    }

    /**
     * @return array<string, Definition>
     */
    protected static function build(): array
    {
        $site = fn () => static::defaultSite();

        $definitions = [

            // content/collections/{handle}.yaml
            static::handled('collections', 'collections', Data\Collection::class, 'collections',
                ['title'], 'settings', 'content', 'collections'),

            // resources/blueprints/{namespace…}/{handle}.yaml
            new Definition(
                name: 'blueprints',
                group: 'blueprints',
                item: Data\Blueprint::class,
                repository: Repositories\BlueprintRepository::class,
                table: 'blueprints',
                columns: ['namespace', 'handle'],
                key: ['namespace', 'handle'],
                json: 'data',
                casts: [],
                uuid: false,
                fileRoot: 'resources',
                fileDir: 'blueprints',
                fileExtension: 'yaml',
                filePath: fn (array $a) => ltrim(str_replace('.', '/', (string) ($a['namespace'] ?? '')).'/'.$a['handle'].'.yaml', '/'),
                parsePath: function (string $path) {
                    [$folders, $handle] = static::split($path, 'yaml');

                    return $handle === '' ? null : ['namespace' => implode('.', $folders), 'handle' => $handle];
                },
            ),

            // resources/fieldsets/{handle}.yaml
            static::handled('fieldsets', 'blueprints', Data\Fieldset::class, 'fieldsets',
                [], 'data', 'resources', 'fieldsets'),

            // content/collections/{collection}/{slug}.md, or …/{collection}/{site}/{slug}.md for other sites
            new Definition(
                name: 'entries',
                group: 'entries',
                item: Data\Entry::class,
                repository: Repositories\EntryRepository::class,
                table: 'entries',
                columns: ['id', 'site', 'origin_id', 'published', 'slug', 'uri', 'date', 'order', 'collection', 'blueprint'],
                key: ['id'],
                json: 'data',
                casts: ['published' => 'bool', 'order' => 'int', 'date' => 'datetime'],
                uuid: true,
                fileRoot: 'content',
                fileDir: 'collections',
                fileExtension: 'md',
                filePath: function (array $a) use ($site) {
                    $folder = ($a['site'] ?? $site()) === $site() ? '' : $a['site'].'/';
                    $slug = $a['slug'] ?? $a['id'];

                    return "{$a['collection']}/{$folder}{$slug}.md";
                },
                parsePath: function (string $path) use ($site) {
                    [$folders, $slug] = static::split($path, 'md');

                    return match (count($folders)) {
                        1 => ['collection' => $folders[0], 'site' => $site(), 'slug' => $slug],
                        2 => ['collection' => $folders[0], 'site' => $folders[1], 'slug' => $slug],
                        default => null,
                    };
                },
            ),

            // content/navigation/{handle}.yaml
            static::handled('navigations', 'navigations', Data\Navigation::class, 'navigations',
                ['title'], 'settings', 'content', 'navigation'),

            // content/trees/navigation/{handle}.yaml, content/trees/collections/{handle}.yaml
            new Definition(
                name: 'trees',
                group: 'trees',
                item: Data\Tree::class,
                repository: Repositories\TreeRepository::class,
                table: 'trees',
                columns: ['type', 'handle', 'locale', 'settings'],
                key: ['type', 'handle', 'locale'],
                json: 'tree',
                casts: ['settings' => 'array'],
                uuid: false,
                fileRoot: 'content',
                fileDir: 'trees',
                fileExtension: 'yaml',
                filePath: function (array $a) use ($site) {
                    $dir = $a['type'] === 'collection' ? 'collections' : 'navigation';
                    $folder = ($a['locale'] ?? $site()) === $site() ? '' : $a['locale'].'/';

                    return "{$dir}/{$folder}{$a['handle']}.yaml";
                },
                parsePath: function (string $path) use ($site) {
                    [$folders, $handle] = static::split($path, 'yaml');
                    $type = match ($folders[0] ?? null) {
                        'collections' => 'collection',
                        'navigation' => 'navigation',
                        default => null,
                    };

                    return match (true) {
                        $type === null || $handle === '' => null,
                        count($folders) === 1 => ['type' => $type, 'handle' => $handle, 'locale' => $site()],
                        count($folders) === 2 => ['type' => $type, 'handle' => $handle, 'locale' => $folders[1]],
                        default => null,
                    };
                },
                fileNestKey: 'tree',
            ),

            // content/taxonomies/{handle}.yaml
            static::handled('taxonomies', 'taxonomies', Data\Taxonomy::class, 'taxonomies',
                ['title', 'sites'], 'settings', 'content', 'taxonomies', ['sites' => 'array']),

            // content/taxonomies/{taxonomy}/{slug}.yaml, or …/{taxonomy}/{site}/{slug}.yaml
            new Definition(
                name: 'terms',
                group: 'terms',
                item: Data\Term::class,
                repository: Repositories\TermRepository::class,
                table: 'terms',
                columns: ['taxonomy', 'slug', 'site', 'uri'],
                key: ['taxonomy', 'slug', 'site'],
                json: 'data',
                casts: [],
                uuid: false,
                fileRoot: 'content',
                fileDir: 'taxonomies',
                fileExtension: 'yaml',
                filePath: function (array $a) use ($site) {
                    $folder = ($a['site'] ?? $site()) === $site() ? '' : $a['site'].'/';

                    return "{$a['taxonomy']}/{$folder}{$a['slug']}.yaml";
                },
                parsePath: function (string $path) use ($site) {
                    [$folders, $slug] = static::split($path, 'yaml');

                    return match (count($folders)) {
                        1 => ['taxonomy' => $folders[0], 'slug' => $slug, 'site' => $site()],
                        2 => ['taxonomy' => $folders[0], 'slug' => $slug, 'site' => $folders[1]],
                        default => null,
                    };
                },
            ),

            // content/globals/{handle}.yaml
            static::handled('global_sets', 'globals', Data\GlobalSet::class, 'global_sets',
                ['title'], 'settings', 'content', 'globals'),

            // content/globals/{locale}/{handle}.yaml
            new Definition(
                name: 'global_set_variables',
                group: 'globals',
                item: Data\GlobalVariables::class,
                repository: Repositories\Repository::class,
                table: 'global_set_variables',
                columns: ['handle', 'locale', 'origin'],
                key: ['handle', 'locale'],
                json: 'data',
                casts: [],
                uuid: false,
                fileRoot: 'content',
                fileDir: 'globals',
                fileExtension: 'yaml',
                filePath: fn (array $a) => "{$a['locale']}/{$a['handle']}.yaml",
                parsePath: function (string $path) {
                    [$folders, $handle] = static::split($path, 'yaml');

                    return count($folders) === 1 && $handle !== ''
                        ? ['locale' => $folders[0], 'handle' => $handle]
                        : null;
                },
            ),

            // content/assets/{handle}.yaml
            static::handled('asset_containers', 'assets', Data\AssetContainer::class, 'asset_containers',
                ['title', 'disk'], 'settings', 'content', 'assets'),

            // content/assets/{container}/{path}.yaml, e.g. media/covers/night.jpg.yaml
            new Definition(
                name: 'assets_meta',
                group: 'assets',
                item: Data\Asset::class,
                repository: Repositories\AssetRepository::class,
                table: 'assets_meta',
                columns: ['container', 'path', 'folder', 'basename', 'filename', 'extension'],
                key: ['container', 'path'],
                json: 'meta',
                casts: [],
                uuid: false,
                fileRoot: 'content',
                fileDir: 'assets',
                fileExtension: 'yaml',
                filePath: fn (array $a) => "{$a['container']}/{$a['path']}.yaml",
                parsePath: function (string $path) {
                    $segments = explode('/', trim($path, '/'));

                    if (count($segments) < 2 || ! str_ends_with($path, '.yaml')) {
                        return null;
                    }

                    $container = array_shift($segments);
                    $filePath = substr(implode('/', $segments), 0, -5);

                    return Data\Asset::partsFromPath($container, $filePath);
                },
            ),

            // storage/booklet/revisions/{key…}/{id}.yaml
            new Definition(
                name: 'revisions',
                group: 'revisions',
                item: Data\Revision::class,
                repository: Repositories\RevisionRepository::class,
                table: 'revisions',
                columns: ['id', 'key', 'action', 'user', 'message'],
                key: ['id'],
                json: 'attributes',
                casts: [],
                uuid: true,
                fileRoot: 'storage',
                fileDir: 'revisions',
                fileExtension: 'yaml',
                filePath: fn (array $a) => trim($a['key'], '/')."/{$a['id']}.yaml",
                parsePath: function (string $path) {
                    [$folders, $id] = static::split($path, 'yaml');

                    return $folders === [] || $id === '' ? null : ['key' => implode('/', $folders), 'id' => $id];
                },
                fileNestKey: 'attributes',
            ),

            // resources/forms/{handle}.yaml
            static::handled('forms', 'forms', Data\Form::class, 'forms',
                ['title'], 'settings', 'resources', 'forms'),

            // storage/booklet/forms/{form}/{id}.yaml
            new Definition(
                name: 'form_submissions',
                group: 'forms',
                item: Data\Submission::class,
                repository: Repositories\SubmissionRepository::class,
                table: 'form_submissions',
                columns: ['id', 'form'],
                key: ['id'],
                json: 'data',
                casts: [],
                uuid: true,
                fileRoot: 'storage',
                fileDir: 'forms',
                fileExtension: 'yaml',
                filePath: fn (array $a) => "{$a['form']}/{$a['id']}.yaml",
                parsePath: function (string $path) {
                    [$folders, $id] = static::split($path, 'yaml');

                    return count($folders) === 1 && $id !== '' ? ['form' => $folders[0], 'id' => $id] : null;
                },
            ),
        ];

        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->name] = $definition;
        }

        return $keyed;
    }
}
