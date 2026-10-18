<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\Filter\Criteria\ChoiceCriteriaBuilder;

/**
 * Choice filter: scalar → eq, array → IN; see {@see ChoiceCriteriaBuilder}.
 */
class ChoiceFilterApplier extends CriteriaFilterApplier
{
    protected function createCriteriaBuilder(): FilterCriteriaBuilderInterface
    {
        return new ChoiceCriteriaBuilder();
    }
}
