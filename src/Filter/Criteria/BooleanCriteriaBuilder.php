<?php

namespace Fedale\GridviewBundle\Filter\Criteria;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;

/**
 * Boolean filter: '1'/1/true and '0'/0/false; anything else (the "any" choice
 * included) adds no constraint.
 */
class BooleanCriteriaBuilder implements FilterCriteriaBuilderInterface
{
    public function build(string $attribute, mixed $rawValue, array $options = []): array
    {
        if (in_array($rawValue, ['1', 1, true], true)) {
            return [new FilterCriterion($attribute, FilterOperator::Eq, true)];
        }
        if (in_array($rawValue, ['0', 0, false], true)) {
            return [new FilterCriterion($attribute, FilterOperator::Eq, false)];
        }

        return [];
    }
}
