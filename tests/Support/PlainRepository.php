<?php

namespace Fedale\GridviewBundle\Tests\Support;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Test double satisfying EntityManagerInterface::getRepository() return type
 * with no search() of its own, so the data provider takes the fallback
 * createQueryBuilder() path (where eager fetch-joins are applied).
 */
class PlainRepository extends EntityRepository
{
    public function __construct(private QueryBuilder $queryBuilderToReturn)
    {
    }

    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return $this->queryBuilderToReturn;
    }
}
