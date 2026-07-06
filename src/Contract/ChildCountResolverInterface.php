<?php

namespace Fedale\GridviewBundle\Contract;

use Fedale\GridviewBundle\Grid\GroupingConfig;

/**
 * Opt-in capability for a {@see ChildRowResolverInterface} that can count a
 * page's children without fetching them — used in lazy mode to gate the expand
 * toggle (and show a count badge) before any child row is actually loaded. A
 * resolver that doesn't implement this leaves the toggle always visible.
 */
interface ChildCountResolverInterface
{
    /**
     * Child counts for a whole page of parents at once, one query. Keyed by the
     * parent identifier read from {@see GroupingConfig::getParentKey()}.
     *
     * @param iterable<\Fedale\GridviewBundle\Row\Row> $parentRows
     *
     * @return array<int|string, int>
     */
    public function countForParents(iterable $parentRows, GroupingConfig $config): array;
}
