<?php

namespace Fedale\GridviewBundle\Filter\Criteria;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;

/**
 * Filter type → criteria builder, the twin of
 * {@see \Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry} for grids
 * that are not backed by Doctrine.
 */
class FilterCriteriaBuilderRegistry
{
    /**
     * @var array<string, class-string<FilterCriteriaBuilderInterface>>
     */
    private array $types = [
        'text'     => TextCriteriaBuilder::class,
        'boolean'  => BooleanCriteriaBuilder::class,
        'date'     => DateCriteriaBuilder::class,
        'number'   => NumberCriteriaBuilder::class,
        'choice'   => ChoiceCriteriaBuilder::class,
        'relation' => RelationCriteriaBuilder::class,
    ];

    /**
     * @var array<string, FilterCriteriaBuilderInterface>
     */
    private array $instances = [];

    public function register(string $type, FilterCriteriaBuilderInterface $builder): void
    {
        $this->instances[$type] = $builder;
    }

    public function has(string $type): bool
    {
        return isset($this->instances[$type]) || isset($this->types[$type]);
    }

    public function get(string $type): FilterCriteriaBuilderInterface
    {
        if (isset($this->instances[$type])) {
            return $this->instances[$type];
        }

        if (!isset($this->types[$type])) {
            $known = array_unique(array_merge(array_keys($this->instances), array_keys($this->types)));

            throw new \InvalidArgumentException(sprintf(
                'Unknown filter criteria builder type "%s". Known types: %s.',
                $type,
                implode(', ', $known)
            ));
        }

        $class = $this->types[$type];

        return $this->instances[$type] = new $class();
    }
}
