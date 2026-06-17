<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Doctrine\ORM\QueryBuilder;

/**
 * Hybrid number filter: keeps the from/to range UI but also understands an
 * operator/range syntax typed directly into either bound (like the text filter):
 *   ">5", ">=10", "<3", "<=3", "=10", "!=10"/"<>10", or a range "1-5".
 *
 * A bound that is a plain number keeps the original semantics — 'from' → >=,
 * 'to' → <=. A bound carrying an operator/range expression applies that
 * expression as-is. Bounds AND-combine, so "from = >5" + "to = 20" → (5, 20].
 */
class NumberFilterApplier extends AbstractFilterApplier
{
    public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
    {
        if (!is_array($rawValue) || $this->isBlank($rawValue)) {
            return;
        }

        $separator = (string) ($options['range_separator'] ?? '-');

        $this->applyBound($qb, $dqlField, $rawValue['from'] ?? null, 'gte', $separator);
        $this->applyBound($qb, $dqlField, $rawValue['to'] ?? null, 'lte', $separator);
    }

    /**
     * @param string $fallbackOp comparison used when the bound is a plain number
     */
    private function applyBound(QueryBuilder $qb, string $dqlField, mixed $value, string $fallbackOp, string $separator): void
    {
        if ($value === null) {
            return;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        // Operator/range expression takes precedence over the plain-number fallback.
        if ($this->applyExpression($qb, $dqlField, $value, $separator)) {
            return;
        }

        if (is_numeric($value)) {
            $this->compare($qb, $dqlField, $fallbackOp, $this->num($value));
        }
        // Non-numeric junk (e.g. "abc") is skipped silently.
    }

    /**
     * @return bool true when an operator prefix or a range was recognized and applied
     */
    private function applyExpression(QueryBuilder $qb, string $dqlField, string $value, string $separator): bool
    {
        if (preg_match('/^(>=|<=|<>|!=|=|>|<)\s*(-?\d+(?:[.,]\d+)?)$/', $value, $m)) {
            $op = match ($m[1]) {
                '='        => 'eq',
                '!=', '<>' => 'neq',
                '>'        => 'gt',
                '>='       => 'gte',
                '<'        => 'lt',
                '<='       => 'lte',
            };
            $this->compare($qb, $dqlField, $op, $this->num($m[2]));

            return true;
        }

        // Range "a<sep>b" with non-negative bounds (a leading '-' would clash with
        // the default '-' separator; use ">=-5" for negative lower bounds instead).
        $sep = preg_quote($separator, '/');
        if ($separator !== '' && preg_match('/^(\d+(?:[.,]\d+)?)\s*' . $sep . '\s*(\d+(?:[.,]\d+)?)$/', $value, $m)) {
            $a = $this->num($m[1]);
            $b = $this->num($m[2]);
            if ($a > $b) {
                [$a, $b] = [$b, $a];
            }
            $pa = $this->uniqueParam();
            $pb = $this->uniqueParam();
            $qb->andWhere($qb->expr()->between($dqlField, ':' . $pa, ':' . $pb));
            $qb->setParameter($pa, $a);
            $qb->setParameter($pb, $b);

            return true;
        }

        return false;
    }

    private function compare(QueryBuilder $qb, string $dqlField, string $op, int|float $num): void
    {
        $p = $this->uniqueParam();
        $qb->andWhere($qb->expr()->{$op}($dqlField, ':' . $p));
        $qb->setParameter($p, $num);
    }

    private function num(string $value): int|float
    {
        return str_replace(',', '.', trim($value)) + 0;
    }
}
