<?php

namespace App\Booklet;

use App\Booklet\Repositories\AssetRepository;
use App\Booklet\Repositories\BlueprintRepository;
use App\Booklet\Repositories\EntryRepository;
use App\Booklet\Repositories\Repository;
use App\Booklet\Repositories\RevisionRepository;
use App\Booklet\Repositories\SubmissionRepository;
use App\Booklet\Repositories\TermRepository;
use App\Booklet\Repositories\TreeRepository;
use App\Booklet\Schema\Definition;
use App\Booklet\Schema\Definitions;
use App\Booklet\Stores\StoreFactory;

/**
 * The front door to Booklet's content. Use it through the facade:
 *
 *   Booklet::entries()->whereCollection('posts');
 *   Booklet::collections()->find('posts');
 *   Booklet::globals('site')?->get('site_name');
 */
class BookletManager
{
    /** @var array<string, Repository> */
    protected array $repositories = [];

    public function repository(string $name): Repository
    {
        if (isset($this->repositories[$name])) {
            return $this->repositories[$name];
        }

        $definition = Definitions::get($name);

        return $this->repositories[$name] = new ($definition->repository)(
            $definition,
            StoreFactory::make($definition),
        );
    }

    /**
     * A repository that has its own class (entries, terms…), checked to be it.
     *
     * @template T of Repository
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function typed(string $name, string $class): Repository
    {
        $repository = $this->repository($name);
        if (! $repository instanceof $class) {
            throw new \LogicException("The {$name} repository should be a {$class}.");
        }

        return $repository;
    }

    /** @return Repository<Data\Collection> */
    public function collections(): Repository
    {
        return $this->repository('collections');
    }

    public function blueprints(): BlueprintRepository
    {
        return $this->typed('blueprints', BlueprintRepository::class);
    }

    /** @return Repository<Data\Fieldset> */
    public function fieldsets(): Repository
    {
        return $this->repository('fieldsets');
    }

    public function entries(): EntryRepository
    {
        return $this->typed('entries', EntryRepository::class);
    }

    /** @return Repository<Data\Navigation> */
    public function navigations(): Repository
    {
        return $this->repository('navigations');
    }

    public function trees(): TreeRepository
    {
        return $this->typed('trees', TreeRepository::class);
    }

    /** @return Repository<Data\Taxonomy> */
    public function taxonomies(): Repository
    {
        return $this->repository('taxonomies');
    }

    public function terms(): TermRepository
    {
        return $this->typed('terms', TermRepository::class);
    }

    /** @return Repository<Data\GlobalSet> */
    public function globalSets(): Repository
    {
        return $this->repository('global_sets');
    }

    /** @return Repository<Data\GlobalVariables> */
    public function globalVariables(): Repository
    {
        return $this->repository('global_set_variables');
    }

    /**
     * A global set's values for a site, e.g. Booklet::globals('site')->get('tagline').
     */
    public function globals(string $handle, ?string $site = null): ?Data\GlobalVariables
    {
        return $this->globalVariables()->find(['handle' => $handle, 'locale' => $site ?? config('booklet.default_site')]);
    }

    /** @return Repository<Data\AssetContainer> */
    public function assetContainers(): Repository
    {
        return $this->repository('asset_containers');
    }

    public function assets(): AssetRepository
    {
        return $this->typed('assets_meta', AssetRepository::class);
    }

    public function revisions(): RevisionRepository
    {
        return $this->typed('revisions', RevisionRepository::class);
    }

    /** @return Repository<Data\Form> */
    public function forms(): Repository
    {
        return $this->repository('forms');
    }

    public function submissions(): SubmissionRepository
    {
        return $this->typed('form_submissions', SubmissionRepository::class);
    }

    /**
     * Which driver a group is using right now, e.g. driver('entries') === 'file'.
     */
    public function driver(string $group): string
    {
        $definition = collect(Definitions::inGroup($group))->first()
            ?? throw new \InvalidArgumentException("Unknown Booklet group [{$group}].");

        return StoreFactory::driverFor($definition);
    }

    /**
     * Forget resolved repositories, e.g. after changing drivers at runtime.
     */
    public function flush(): void
    {
        $this->repositories = [];
    }

    /**
     * Copy every item in the given groups from one driver to another.
     * Used by booklet:export and booklet:import.
     *
     * @param  string[]  $groups
     * @return array<string, int> repository name => items copied
     */
    public function copy(array $groups, string $from, string $to, bool $fresh = false): array
    {
        $counts = [];

        foreach ($groups as $group) {
            /** @var Definition $definition */
            foreach (Definitions::inGroup($group) as $definition) {
                $source = StoreFactory::make($definition, $from);
                $target = StoreFactory::make($definition, $to);

                if ($fresh) {
                    $target->truncate();
                }

                $counts[$definition->name] = 0;

                foreach ($source->all() as $record) {
                    $target->save($definition->castAttributes($record['attributes']), $record['data']);
                    $counts[$definition->name]++;
                }
            }
        }

        $this->flush();

        return $counts;
    }
}
