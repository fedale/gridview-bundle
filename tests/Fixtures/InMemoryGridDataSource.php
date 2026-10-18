<?php

namespace Fedale\GridviewBundle\Tests\Fixtures;

use Fedale\GridviewBundle\Contract\GridDataSourceInterface;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Fedale\GridviewBundle\DataSource\SortDirection;

/**
 * A data source over a PHP array that runs the query for real, and records
 * every call with the query it received.
 */
class InMemoryGridDataSource implements GridDataSourceInterface
{
    /** @var list<array{0: string, 1: GridQuery}> */
    public array $calls = [];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    public function count(GridQuery $query): int
    {
        $this->calls[] = ['count', $query];

        return count($this->matching($query));
    }

    public function fetch(GridQuery $query): iterable
    {
        $this->calls[] = ['fetch', $query];

        $rows = $this->matching($query);
        foreach (array_reverse($query->sort) as $order) {
            usort($rows, static fn(array $a, array $b): int => ($a[$order->attribute] <=> $b[$order->attribute]) * ($order->direction === SortDirection::Desc ? -1 : 1));
        }

        return array_slice($rows, $query->offset, $query->limit);
    }

    public function lastQuery(string $call): ?GridQuery
    {
        foreach (array_reverse($this->calls) as [$name, $query]) {
            if ($name === $call) {
                return $query;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matching(GridQuery $query): array
    {
        return array_values(array_filter($this->rows, function (array $row) use ($query): bool {
            foreach ($query->criteria as $criterion) {
                if (!$this->satisfies($row[$criterion->attribute] ?? null, $criterion)) {
                    return false;
                }
            }

            if ($query->searchTerm === null) {
                return true;
            }
            foreach ($query->searchFields as $field) {
                if (str_contains(strtolower((string) ($row[$field] ?? '')), strtolower($query->searchTerm))) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function satisfies(mixed $value, FilterCriterion $criterion): bool
    {
        $expected = $criterion->value;
        if ($criterion->caseInsensitive) {
            $value = strtolower((string) $value);
            $expected = is_array($expected) ? array_map('strtolower', $expected) : strtolower((string) $expected);
        }

        return match ($criterion->operator) {
            FilterOperator::Eq => $value == $expected,
            FilterOperator::Neq => $value != $expected,
            FilterOperator::Lt => $value < $expected,
            FilterOperator::Lte => $value <= $expected,
            FilterOperator::Gt => $value > $expected,
            FilterOperator::Gte => $value >= $expected,
            FilterOperator::Contains => str_contains((string) $value, (string) $expected),
            FilterOperator::NotContains => !str_contains((string) $value, (string) $expected),
            FilterOperator::StartsWith => str_starts_with((string) $value, (string) $expected),
            FilterOperator::EndsWith => str_ends_with((string) $value, (string) $expected),
            FilterOperator::In => in_array($value, $expected),
            FilterOperator::Between => $value >= $expected[0] && $value <= $expected[1],
        };
    }
}
