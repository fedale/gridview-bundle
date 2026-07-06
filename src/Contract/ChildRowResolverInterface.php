<?php

namespace Fedale\GridviewBundle\Contract;

use Fedale\GridviewBundle\Grid\GroupingConfig;
use Fedale\GridviewBundle\Row\Row;

/**
 * Resolves the child rows of a grouped grid's parent rows. Kept separate from
 * the data provider so grouping is a pluggable capability: each backend (a
 * Doctrine relation, an array, a remote API) supplies its own resolver, and a
 * provider that cannot resolve relations simply offers none.
 */
interface ChildRowResolverInterface
{
    /**
     * Children for a whole page of parents at once (eager mode). Keyed by the
     * parent identifier read from {@see GroupingConfig::getParentKey()}, so the
     * caller can attach each list to its parent row.
     *
     * @param iterable<Row> $parentRows
     *
     * @return array<int|string, list<Row>>
     */
    public function resolveForParents(iterable $parentRows, GroupingConfig $config): array;

    /**
     * Children for a single parent, fetched on demand (lazy mode).
     *
     * @return list<Row>
     */
    public function resolveForParent(int|string $parentKey, GroupingConfig $config): array;
}
