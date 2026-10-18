<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\Filter\Criteria\NumberCriteriaBuilder;

/**
 * Number filter; the syntax is documented on {@see NumberCriteriaBuilder}.
 */
class NumberFilterApplier extends CriteriaFilterApplier
{
    protected function createCriteriaBuilder(): FilterCriteriaBuilderInterface
    {
        return new NumberCriteriaBuilder();
    }
}
