<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\Filter\Criteria\DateCriteriaBuilder;

/**
 * Date range filter; see {@see DateCriteriaBuilder}.
 */
class DateFilterApplier extends CriteriaFilterApplier
{
    protected function createCriteriaBuilder(): FilterCriteriaBuilderInterface
    {
        return new DateCriteriaBuilder();
    }
}
