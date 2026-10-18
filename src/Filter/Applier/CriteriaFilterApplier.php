<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Doctrine\ORM\QueryBuilder;
use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;

/**
 * An applier split in its two halves: a criteria builder parses the raw value
 * (the same one a data-source grid uses), and {@see DqlCriterionWriter} turns
 * each criterion into DQL. One parser, so a filter means the same thing
 * whatever the grid is backed by.
 */
abstract class CriteriaFilterApplier extends AbstractFilterApplier
{
    private ?FilterCriteriaBuilderInterface $criteriaBuilder = null;

    private ?DqlCriterionWriter $writer = null;

    abstract protected function createCriteriaBuilder(): FilterCriteriaBuilderInterface;

    /** Explicit parameter type for the bound values, null to let Doctrine infer it. */
    protected function parameterType(): ?string
    {
        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
    {
        $this->criteriaBuilder ??= $this->createCriteriaBuilder();
        $this->writer ??= new DqlCriterionWriter();

        foreach ($this->criteriaBuilder->build($dqlField, $rawValue, $options) as $criterion) {
            $this->writer->write($qb, $dqlField, $criterion, $this->parameterType());
        }
    }
}
