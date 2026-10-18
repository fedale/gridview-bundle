<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\Filter\Criteria\TextCriteriaBuilder;

/**
 * Text filter; the syntax is documented on {@see TextCriteriaBuilder}.
 */
class TextFilterApplier extends CriteriaFilterApplier
{
    protected function createCriteriaBuilder(): FilterCriteriaBuilderInterface
    {
        return new TextCriteriaBuilder();
    }
}
