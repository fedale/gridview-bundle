<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Doctrine\ORM\QueryBuilder;

class DateFilterApplier extends AbstractFilterApplier
{
    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
    {
        if (!is_array($rawValue) || $this->isBlank($rawValue)) {
            return;
        }

        $endOfDay = $options['end_of_day'] ?? true;

        $from = $rawValue['from'] ?? null;
        if (is_string($from) && preg_match(self::ISO_DATE, $from)) {
            $p = $this->uniqueParam();
            $qb->andWhere($qb->expr()->gte($dqlField, ':' . $p));
            $qb->setParameter($p, new \DateTime($from));
        }

        $to = $rawValue['to'] ?? null;
        if (is_string($to) && preg_match(self::ISO_DATE, $to)) {
            $p = $this->uniqueParam();
            $qb->andWhere($qb->expr()->lte($dqlField, ':' . $p));
            $qb->setParameter($p, new \DateTime($to . ($endOfDay ? ' 23:59:59' : '')));
        }
    }
}
