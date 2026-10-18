<?php

namespace Fedale\GridviewBundle\Filter\Criteria;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\Filter\BlankValueTrait;

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
 *
 * Option: range_separator (default '-') for the legacy "a-b" range.
 */
class NumberCriteriaBuilder implements FilterCriteriaBuilderInterface
{
    use BlankValueTrait;

    public function build(string $attribute, mixed $rawValue, array $options = []): array
    {
        $separator = (string) ($options['range_separator'] ?? '-');

        // Range widget: two named bounds combining with AND (from → >=, to → <=).
        if (is_array($rawValue)) {
            if ($this->isBlank($rawValue)) {
                return [];
            }

            return [
                ...$this->bound($attribute, $rawValue['from'] ?? null, FilterOperator::Gte, $separator),
                ...$this->bound($attribute, $rawValue['to'] ?? null, FilterOperator::Lte, $separator),
            ];
        }

        // Single input: one scalar in which a plain number means "equals".
        return $this->bound($attribute, $rawValue, FilterOperator::Eq, $separator);
    }

    /**
     * @param FilterOperator $fallback comparison used when the bound is a plain number
     *                                 (Eq for the single input, Gte/Lte for a bound)
     *
     * @return list<FilterCriterion>
     */
    private function bound(string $attribute, mixed $value, FilterOperator $fallback, string $separator): array
    {
        if ($value === null) {
            return [];
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        // Operator/range expression takes precedence over the plain-number fallback.
        $criterion = $this->expression($attribute, $value, $separator);
        if ($criterion !== null) {
            return [$criterion];
        }

        if (is_numeric($value)) {
            return [new FilterCriterion($attribute, $fallback, $this->num($value))];
        }

        // Non-numeric junk (e.g. "abc") is skipped silently.
        return [];
    }

    /**
     * The criterion of an operator prefix or a range, null when the value is neither.
     */
    private function expression(string $attribute, string $value, string $separator): ?FilterCriterion
    {
        if (preg_match('/^(>=|<=|<>|!=|=|>|<)\s*(-?\d+(?:[.,]\d+)?)$/', $value, $m)) {
            $operator = match ($m[1]) {
                '='        => FilterOperator::Eq,
                '!=', '<>' => FilterOperator::Neq,
                '>'        => FilterOperator::Gt,
                '>='       => FilterOperator::Gte,
                '<'        => FilterOperator::Lt,
                '<='       => FilterOperator::Lte,
            };

            return new FilterCriterion($attribute, $operator, $this->num($m[2]));
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
            return $this->between($attribute, $this->num($m[1]), $this->num($m[2]));
        }

        // Legacy range "a<sep>b" with non-negative bounds (a leading '-' would
        // clash with the default '-' separator; use ">=-5" for negative lower
        // bounds, or the "a -> b" form which accepts negatives).
        $sep = preg_quote($separator, '/');
        if ($separator !== '' && preg_match('/^(\d+(?:[.,]\d+)?)\s*' . $sep . '\s*(\d+(?:[.,]\d+)?)$/', $value, $m)) {
            return $this->between($attribute, $this->num($m[1]), $this->num($m[2]));
        }

        return null;
    }

    private function between(string $attribute, int|float $a, int|float $b): FilterCriterion
    {
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }

        return new FilterCriterion($attribute, FilterOperator::Between, [$a, $b]);
    }

    /**
     * Callers only pass values already matched as numbers; "+ 0" keeps an
     * integer an int and a decimal a float.
     */
    private function num(string $value): int|float
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? $value + 0 : 0;
    }
}
