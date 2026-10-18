<?php

namespace Fedale\GridviewBundle\Filter\Criteria;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\Filter\BlankValueTrait;

/**
 * Choice filter: a scalar → Eq, a list (multiple choice) → In, blanks removed.
 * Values stay as submitted: casting them is up to whoever runs the query.
 */
class ChoiceCriteriaBuilder implements FilterCriteriaBuilderInterface
{
    use BlankValueTrait;

    public function build(string $attribute, mixed $rawValue, array $options = []): array
    {
        if ($this->isBlank($rawValue)) {
            return [];
        }

        if (is_array($rawValue)) {
            $values = array_values(array_filter($rawValue, fn($v) => $v !== null && $v !== ''));

            return $values === [] ? [] : [new FilterCriterion($attribute, FilterOperator::In, $values)];
        }

        return [new FilterCriterion($attribute, FilterOperator::Eq, $rawValue)];
    }
}
