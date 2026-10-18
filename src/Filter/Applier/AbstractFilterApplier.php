<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Fedale\GridviewBundle\Contract\FilterApplierInterface;
use Fedale\GridviewBundle\Filter\BlankValueTrait;

abstract class AbstractFilterApplier implements FilterApplierInterface
{
    use BlankValueTrait;

    protected function uniqueParam(): string
    {
        return 'p_' . uniqid();
    }
}
