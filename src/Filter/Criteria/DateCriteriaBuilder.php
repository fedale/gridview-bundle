<?php

namespace Fedale\GridviewBundle\Filter\Criteria;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\Filter\BlankValueTrait;

/**
 * Date range filter: {from, to}, each an ISO date (YYYY-MM-DD); anything else
 * in a bound is ignored. 'from' → Gte at midnight, 'to' → Lte at 23:59:59, or
 * at midnight when the end_of_day option (default true) is false.
 */
class DateCriteriaBuilder implements FilterCriteriaBuilderInterface
{
    use BlankValueTrait;

    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function build(string $attribute, mixed $rawValue, array $options = []): array
    {
        if (!is_array($rawValue) || $this->isBlank($rawValue)) {
            return [];
        }

        $endOfDay = $options['end_of_day'] ?? true;
        $criteria = [];

        $from = $rawValue['from'] ?? null;
        if (is_string($from) && preg_match(self::ISO_DATE, $from)) {
            $criteria[] = new FilterCriterion($attribute, FilterOperator::Gte, new \DateTimeImmutable($from));
        }

        $to = $rawValue['to'] ?? null;
        if (is_string($to) && preg_match(self::ISO_DATE, $to)) {
            $criteria[] = new FilterCriterion($attribute, FilterOperator::Lte, new \DateTimeImmutable($to . ($endOfDay ? ' 23:59:59' : '')));
        }

        return $criteria;
    }
}
