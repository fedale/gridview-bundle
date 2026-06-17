<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Fedale\GridviewBundle\Contract\FilterApplierInterface;

abstract class AbstractFilterApplier implements FilterApplierInterface
{
    /**
     * '0' is a valid value (e.g. boolean false), so never use empty() here.
     */
    protected function isBlank(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item !== null && $item !== '') {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    protected function uniqueParam(): string
    {
        return 'p_' . uniqid();
    }
}
