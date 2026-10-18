<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Doctrine\ORM\QueryBuilder;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;

/**
 * Writes a {@see FilterCriterion} as an AND-ed DQL condition: the query half
 * of the built-in appliers, whose parsing lives in the criteria builders.
 *
 * Case-insensitive criteria compare LOWER(field) with the lowercased value.
 * Dates are bound as \DateTime, as the appliers always did: Doctrine infers a
 * different parameter type for \DateTimeImmutable.
 */
final class DqlCriterionWriter
{
    /**
     * @param string|null $type explicit parameter type for scalar values (e.g. Types::BOOLEAN), null to let Doctrine infer it
     */
    public function write(QueryBuilder $qb, string $dqlField, FilterCriterion $criterion, ?string $type = null): void
    {
        $expr  = $qb->expr();
        $ci    = $criterion->caseInsensitive;
        $field = $ci ? (string) $expr->lower($dqlField) : $dqlField;
        $value = $criterion->value;

        switch ($criterion->operator) {
            case FilterOperator::In:
                $p = $this->uniqueParam();
                $qb->andWhere($expr->in($field, ':' . $p));
                $qb->setParameter($p, $ci ? array_map(fn($v) => strtolower((string) $v), $value) : $value);

                return;
            case FilterOperator::Between:
                $pa = $this->uniqueParam();
                $pb = $this->uniqueParam();
                $qb->andWhere($expr->between($field, ':' . $pa, ':' . $pb));
                $qb->setParameter($pa, $this->bindable($value[0], $ci));
                $qb->setParameter($pb, $this->bindable($value[1], $ci));

                return;
        }

        [$method, $pattern] = match ($criterion->operator) {
            FilterOperator::Eq          => ['eq', '%s'],
            FilterOperator::Neq         => ['neq', '%s'],
            FilterOperator::Lt          => ['lt', '%s'],
            FilterOperator::Lte         => ['lte', '%s'],
            FilterOperator::Gt          => ['gt', '%s'],
            FilterOperator::Gte         => ['gte', '%s'],
            FilterOperator::Contains    => ['like', '%%%s%%'],
            FilterOperator::NotContains => ['notLike', '%%%s%%'],
            FilterOperator::StartsWith  => ['like', '%s%%'],
            FilterOperator::EndsWith    => ['like', '%%%s'],
        };

        $bound = $this->bindable($value, $ci);
        if ($pattern !== '%s') {
            $bound = sprintf($pattern, $bound);
        }

        $p = $this->uniqueParam();
        $qb->andWhere($expr->{$method}($field, ':' . $p));
        if ($type === null) {
            $qb->setParameter($p, $bound);
        } else {
            $qb->setParameter($p, $bound, $type);
        }
    }

    private function bindable(mixed $value, bool $caseInsensitive): mixed
    {
        if ($value instanceof \DateTimeImmutable) {
            return \DateTime::createFromImmutable($value);
        }

        return $caseInsensitive ? strtolower((string) $value) : $value;
    }

    private function uniqueParam(): string
    {
        return 'p_' . uniqid();
    }
}
