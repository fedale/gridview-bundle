<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Doctrine\ORM\QueryBuilder;

/**
 * Number filter supporting both the single-input widget and the legacy from/to
 * (Min/Max) range widget.
 *
 * Single input (scalar value): the whole comparison is typed inline —
 *   - a plain number ("34") → equals;
 *   - an operator expression (">5", ">=10", "<3", "<=3", "=10", "!=10"/"<>10");
 *   - an inclusive range ("btw 10 and 20", "10 -> 20", "10..20", legacy "10-20").
 *
 * Range widget (array value with from/to): a bound that is a plain number keeps
 * the original semantics — 'from' → >=, 'to' → <= — while a bound carrying an
 * operator/range expression applies that expression as-is. Bounds AND-combine,
 * so "from = >5" + "to = 20" → (5, 20].
 */
class NumberFilterApplier extends AbstractFilterApplier
{
    public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
    {
        $separator = (string) ($options['range_separator'] ?? '-');

        // Range widget: two named bounds combining with AND (from → >=, to → <=).
        if (is_array($rawValue)) {
            if ($this->isBlank($rawValue)) {
                return;
            }

            $this->applyBound($qb, $dqlField, $rawValue['from'] ?? null, 'gte', $separator);
            $this->applyBound($qb, $dqlField, $rawValue['to'] ?? null, 'lte', $separator);

            return;
        }

        // Single input: one scalar in which a plain number means "equals".
        $this->applyBound($qb, $dqlField, $rawValue, 'eq', $separator);
    }

    /**
     * @param string $fallbackOp comparison used when the bound is a plain number
     *                           ('eq' for the single input, 'gte'/'lte' for a bound)
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

        // Explicit / shorthand ranges — bounds may be negative here since a
        // keyword or a two-char separator disambiguates them: "btw a and b",
        // "a -> b", "a..b".
        $num = '(-?\d+(?:[.,]\d+)?)';
        if (
            preg_match('/^btw\s+' . $num . '\s+and\s+' . $num . '$/i', $value, $m)
            || preg_match('/^' . $num . '\s*->\s*' . $num . '$/', $value, $m)
            || preg_match('/^' . $num . '\s*\.\.\s*' . $num . '$/', $value, $m)
        ) {
            $this->applyBetween($qb, $dqlField, $this->num($m[1]), $this->num($m[2]));

            return true;
        }

        // Legacy range "a<sep>b" with non-negative bounds (a leading '-' would
        // clash with the default '-' separator; use ">=-5" for negative lower
        // bounds, or the "a -> b" form which accepts negatives).
        $sep = preg_quote($separator, '/');
        if ($separator !== '' && preg_match('/^(\d+(?:[.,]\d+)?)\s*' . $sep . '\s*(\d+(?:[.,]\d+)?)$/', $value, $m)) {
            $this->applyBetween($qb, $dqlField, $this->num($m[1]), $this->num($m[2]));

            return true;
        }

        return false;
    }

    private function applyBetween(QueryBuilder $qb, string $dqlField, int|float $a, int|float $b): void
    {
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }
        $pa = $this->uniqueParam();
        $pb = $this->uniqueParam();
        $qb->andWhere($qb->expr()->between($dqlField, ':' . $pa, ':' . $pb));
        $qb->setParameter($pa, $a);
        $qb->setParameter($pb, $b);
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
