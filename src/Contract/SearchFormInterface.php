<?php

namespace Fedale\GridviewBundle\Contract;

use Doctrine\ORM\QueryBuilder;

interface SearchFormInterface
{
    public function addFilter(string $name, string $type, array $options);
    public function addGlobalSearch(): void;
    public function getModelType();
    public function applyFilters(QueryBuilder $qb, array $params, array $map): void;
}
