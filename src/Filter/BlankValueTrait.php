<?php

namespace Fedale\GridviewBundle\Filter;

/**
 * The one definition of a filter value that adds no constraint, shared by the
 * criteria builders and the appliers.
 */
trait BlankValueTrait
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
}
