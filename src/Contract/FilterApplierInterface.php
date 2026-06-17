<?php

namespace Fedale\GridviewBundle\Contract;

use Doctrine\ORM\QueryBuilder;

interface FilterApplierInterface
{
    /**
     * Applies the raw submitted filter value to the QueryBuilder as an AND condition.
     * Blank values (null, '', [], all-empty array) must be skipped silently.
     */
    public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void;
}
