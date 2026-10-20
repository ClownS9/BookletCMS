<?php

namespace App\Booklet\Facades;

use App\Booklet\BookletManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Booklet\Repositories\Repository repository(string $name)
 * @method static \App\Booklet\Repositories\Repository<\App\Booklet\Data\Collection> collections()
 * @method static \App\Booklet\Repositories\BlueprintRepository blueprints()
 * @method static \App\Booklet\Repositories\Repository<\App\Booklet\Data\Fieldset> fieldsets()
 * @method static \App\Booklet\Repositories\EntryRepository entries()
 * @method static \App\Booklet\Repositories\Repository<\App\Booklet\Data\Navigation> navigations()
 * @method static \App\Booklet\Repositories\TreeRepository trees()
 * @method static \App\Booklet\Repositories\Repository<\App\Booklet\Data\Taxonomy> taxonomies()
 * @method static \App\Booklet\Repositories\TermRepository terms()
 * @method static \App\Booklet\Repositories\Repository<\App\Booklet\Data\GlobalSet> globalSets()
 * @method static \App\Booklet\Repositories\Repository<\App\Booklet\Data\GlobalVariables> globalVariables()
 * @method static \App\Booklet\Data\GlobalVariables|null globals(string $handle, ?string $site = null)
 * @method static \App\Booklet\Repositories\Repository<\App\Booklet\Data\AssetContainer> assetContainers()
 * @method static \App\Booklet\Repositories\AssetRepository assets()
 * @method static \App\Booklet\Repositories\RevisionRepository revisions()
 * @method static \App\Booklet\Repositories\Repository<\App\Booklet\Data\Form> forms()
 * @method static \App\Booklet\Repositories\SubmissionRepository submissions()
 * @method static string driver(string $group)
 * @method static void flush()
 * @method static array copy(array $groups, string $from, string $to, bool $fresh = false)
 *
 * @see BookletManager
 */
class Booklet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BookletManager::class;
    }
}
