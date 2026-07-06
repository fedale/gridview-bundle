<?php

namespace Fedale\GridviewBundle\Contract;

/**
 * Opt-in marker for data providers that can resolve the child rows of a grouped
 * grid. The grid only enables grouping when its provider implements this and
 * returns a resolver; any other provider degrades to a plain, ungrouped grid.
 */
interface GroupingCapableInterface
{
    public function getChildResolver(): ?ChildRowResolverInterface;
}
