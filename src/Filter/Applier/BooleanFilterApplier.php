<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Doctrine\DBAL\Types\Types;
use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\Filter\Criteria\BooleanCriteriaBuilder;

/**
 * Boolean filter; see {@see BooleanCriteriaBuilder}.
 */
class BooleanFilterApplier extends CriteriaFilterApplier
{
    protected function createCriteriaBuilder(): FilterCriteriaBuilderInterface
    {
        return new BooleanCriteriaBuilder();
    }

    protected function parameterType(): ?string
    {
        return Types::BOOLEAN;
    }
}
