<?php

namespace Fedale\GridviewBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;

trait CreatesQueryBuilderTrait
{
    private function createTestQueryBuilder(): QueryBuilder
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getExpressionBuilder')->willReturn(new Expr());

        return new QueryBuilder($em);
    }

    private function whereDql(QueryBuilder $qb): string
    {
        return (string) $qb->getDQLPart('where');
    }
}
