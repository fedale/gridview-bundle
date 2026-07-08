<?php

namespace Fedale\GridviewBundle\Column;

/**
 * Built-in aggregate functions for a column footer summary. Each case maps to a
 * DQL fragment (dataset scope — one query over the whole filtered result set)
 * and to a PHP reducer (page scope — computed over the current page's rows), so
 * the two scopes yield the same kind of value.
 */
enum FooterAggregate: string
{
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
    case Count = 'count';
    case CountDistinct = 'countDistinct';

    /**
     * DQL aggregate expression for the dataset scope, given an already-qualified
     * field (e.g. 'e.qty').
     */
    public function toDql(string $field): string
    {
        return match ($this) {
            self::Sum           => sprintf('SUM(%s)', $field),
            self::Avg           => sprintf('AVG(%s)', $field),
            self::Min           => sprintf('MIN(%s)', $field),
            self::Max           => sprintf('MAX(%s)', $field),
            self::Count         => sprintf('COUNT(%s)', $field),
            self::CountDistinct => sprintf('COUNT(DISTINCT %s)', $field),
        };
    }

    /**
     * Reduce a list of numeric page values to the aggregate result. Returns null
     * for an empty input (nothing to summarize).
     *
     * @param array<int, int|float> $values
     */
    public function reduce(array $values): int|float|null
    {
        if ($this === self::Count) {
            return \count($values);
        }

        if ($this === self::CountDistinct) {
            return \count(\array_unique($values));
        }

        if ($values === []) {
            return null;
        }

        // Count/CountDistinct already returned above, so only the numeric
        // reducers remain here.
        return match ($this) {
            self::Sum => \array_sum($values),
            self::Avg => \array_sum($values) / \count($values),
            self::Min => \min($values),
            self::Max => \max($values),
        };
    }

    /** Whether this aggregate produces a plain integer count (not a domain value). */
    public function isCount(): bool
    {
        return $this === self::Count || $this === self::CountDistinct;
    }
}
