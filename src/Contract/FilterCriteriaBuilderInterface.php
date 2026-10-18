<?php

namespace Fedale\GridviewBundle\Contract;

use Fedale\GridviewBundle\DataSource\FilterCriterion;

/**
 * Turns the raw submitted value of one filter into normalized criteria: the
 * parsing half of a filter type, with no query language attached. The
 * Doctrine appliers write their DQL from these criteria, and a data source
 * receives them as they are, so a filter means the same thing on both.
 */
interface FilterCriteriaBuilderInterface
{
    /**
     * Blank values (null, '', [], all-empty array) yield no criteria.
     *
     * @param array<string, mixed> $options the filter's applier options (default_operator, end_of_day, ...)
     *
     * @return list<FilterCriterion>
     */
    public function build(string $attribute, mixed $rawValue, array $options = []): array;
}
