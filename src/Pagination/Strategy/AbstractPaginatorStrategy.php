<?php

namespace Fedale\GridviewBundle\Pagination\Strategy;

use Fedale\GridviewBundle\Contract\PaginatorStrategyInterface;

/**
 * Convenience base for paginator strategies: defaults to no Stimulus controllers,
 * so a purely server-rendered strategy only needs getName()/getTemplate().
 */
abstract class AbstractPaginatorStrategy implements PaginatorStrategyInterface
{
    public function getControllers(): array
    {
        return [];
    }
}
