<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

class BooleanFilterApplier extends AbstractFilterApplier
{
    public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
    {
        if (in_array($rawValue, ['1', 1, true], true)) {
            $bool = true;
        } elseif (in_array($rawValue, ['0', 0, false], true)) {
            $bool = false;
        } else {
            return;
        }

        $p = $this->uniqueParam();
        $qb->andWhere($qb->expr()->eq($dqlField, ':' . $p));
        $qb->setParameter($p, $bool, Types::BOOLEAN);
    }
}
