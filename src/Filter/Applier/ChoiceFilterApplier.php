<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Doctrine\ORM\QueryBuilder;

class ChoiceFilterApplier extends AbstractFilterApplier
{
    public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
    {
        if ($this->isBlank($rawValue)) {
            return;
        }

        if (is_array($rawValue)) {
            $values = array_values(array_filter($rawValue, fn ($v) => $v !== null && $v !== ''));
            if ($values === []) {
                return;
            }
            $p = $this->uniqueParam();
            $qb->andWhere($qb->expr()->in($dqlField, ':' . $p));
            $qb->setParameter($p, $values);

            return;
        }

        $p = $this->uniqueParam();
        $qb->andWhere($qb->expr()->eq($dqlField, ':' . $p));
        $qb->setParameter($p, $rawValue);
    }
}
