<?php

namespace App\Booklet\Support;

use App\Booklet\Data\Tree;
use App\Booklet\Facades\Booklet;

/**
 * Reading and rearranging a structured collection's tree (Pages):
 * who is whose parent, nesting a page under another, taking one out.
 * Changes are made in memory; call save() to keep them, which also
 * works out the pages' addresses again.
 */
class PageTree
{
    protected array $branches;

    public function __construct(protected Tree $tree)
    {
        $this->branches = $tree->tree();
    }

    public static function for(string $collection, ?string $site = null): self
    {
        return new self(Booklet::trees()->findOrMake('collection', $collection, $site));
    }

    /**
     * Every entry id, depth first.
     *
     * @return string[]
     */
    public function ids(): array
    {
        return array_column($this->flatten(), 'id');
    }

    /**
     * @return array<int, array{id: string, depth: int, parent: ?string}>
     */
    public function flatten(): array
    {
        $rows = [];
        $walk = function (array $branches, int $depth, ?string $parent) use (&$walk, &$rows) {
            foreach ($branches as $branch) {
                if (! isset($branch['entry'])) {
                    continue;
                }
                $rows[] = ['id' => (string) $branch['entry'], 'depth' => $depth, 'parent' => $parent];
                $walk((array) ($branch['children'] ?? []), $depth + 1, (string) $branch['entry']);
            }
        };
        $walk($this->branches, 0, null);

        return $rows;
    }

    public function parentOf(?string $id): ?string
    {
        foreach ($this->flatten() as $row) {
            if ($row['id'] === $id) {
                return $row['parent'];
            }
        }

        return null;
    }

    public function contains(string $id): bool
    {
        return in_array($id, $this->ids(), true);
    }

    /**
     * Ids of a page's children, grandchildren and so on.
     *
     * @return string[]
     */
    public function descendantsOf(string $id): array
    {
        $found = [];
        $collect = false;
        $depth = null;

        foreach ($this->flatten() as $row) {
            if ($row['id'] === $id) {
                $collect = true;
                $depth = $row['depth'];

                continue;
            }
            if ($collect && $row['depth'] > $depth) {
                $found[] = $row['id'];
            } elseif ($collect) {
                break;
            }
        }

        return $found;
    }

    /**
     * Put a page (with its own sub-pages) under a parent, or at the top level.
     */
    public function place(string $id, ?string $parent = null): static
    {
        if ($parent === $id || ($parent && in_array($parent, $this->descendantsOf($id), true))) {
            return $this; // a page can't go inside itself
        }

        $branch = $this->take($this->branches, $id) ?? ['entry' => $id];

        if ($parent === null || ! $this->contains($parent)) {
            $this->branches[] = $branch;
        } else {
            $this->branches = $this->insert($this->branches, $parent, $branch);
        }

        return $this;
    }

    /**
     * Take a page out; its sub-pages move up to where it was.
     */
    public function remove(string $id): static
    {
        $this->branches = $this->lift($this->branches, $id);

        return $this;
    }

    /**
     * Move a page one place up or down among its siblings.
     */
    public function nudge(string $id, int $by): static
    {
        $this->branches = $this->reorder($this->branches, $id, $by);

        return $this;
    }

    public function save(): void
    {
        $this->tree->setTree($this->branches)->save();
        Booklet::entries()->refreshUris($this->tree->handle(), $this->tree->locale());
    }

    // ---------------------------------------------------------------------

    protected function take(array &$branches, string $id): ?array
    {
        foreach ($branches as $i => $branch) {
            if (($branch['entry'] ?? null) === $id) {
                array_splice($branches, $i, 1);

                return $branch;
            }
            if (! empty($branch['children'])) {
                $children = $branch['children'];
                if ($found = $this->take($children, $id)) {
                    $branches[$i]['children'] = $children;
                    if ($children === []) {
                        unset($branches[$i]['children']);
                    }

                    return $found;
                }
            }
        }

        return null;
    }

    protected function insert(array $branches, string $parent, array $branch): array
    {
        foreach ($branches as $i => $item) {
            if (($item['entry'] ?? null) === $parent) {
                $branches[$i]['children'] = array_values(array_merge((array) ($item['children'] ?? []), [$branch]));

                return $branches;
            }
            if (! empty($item['children'])) {
                $branches[$i]['children'] = $this->insert($item['children'], $parent, $branch);
            }
        }

        return $branches;
    }

    protected function lift(array $branches, string $id): array
    {
        $out = [];
        foreach ($branches as $branch) {
            if (($branch['entry'] ?? null) === $id) {
                foreach ((array) ($branch['children'] ?? []) as $child) {
                    $out[] = $child;
                }

                continue;
            }
            if (! empty($branch['children'])) {
                $branch['children'] = $this->lift($branch['children'], $id);
                if ($branch['children'] === []) {
                    unset($branch['children']);
                }
            }
            $out[] = $branch;
        }

        return $out;
    }

    protected function reorder(array $branches, string $id, int $by): array
    {
        foreach ($branches as $i => $branch) {
            if (($branch['entry'] ?? null) === $id) {
                $j = max(0, min(count($branches) - 1, $i + $by));
                $moved = array_splice($branches, $i, 1);
                array_splice($branches, $j, 0, $moved);

                return array_values($branches);
            }
            if (! empty($branch['children'])) {
                $branches[$i]['children'] = $this->reorder($branch['children'], $id, $by);
            }
        }

        return $branches;
    }
}
