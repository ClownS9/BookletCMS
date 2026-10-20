<?php

namespace App\Booklet\Data;

/**
 * The nesting of a navigation or a structured collection, per site.
 * The data bag is the tree itself, in Statamic's shape:
 *
 *   - entry: 7c1e…            # a page
 *     children:
 *       - entry: 9a2b…
 *   - url: https://…          # a plain link (navigations only)
 *     title: Shop
 */
class Tree extends Item
{
    public static function repositoryName(): string
    {
        return 'trees';
    }

    public function handle(): string
    {
        return $this->attribute('handle');
    }

    public function type(): string
    {
        return $this->attribute('type');
    }

    public function locale(): string
    {
        return $this->attribute('locale');
    }

    public function tree(): array
    {
        return $this->data();
    }

    public function setTree(array $tree): static
    {
        return $this->setData(array_values($tree));
    }

    /**
     * Every entry id in the tree, depth first.
     *
     * @return string[]
     */
    public function entryIds(): array
    {
        $ids = [];
        $walk = function (array $branches) use (&$walk, &$ids) {
            foreach ($branches as $branch) {
                if (isset($branch['entry'])) {
                    $ids[] = $branch['entry'];
                }
                $walk((array) ($branch['children'] ?? []));
            }
        };
        $walk($this->tree());

        return $ids;
    }
}
