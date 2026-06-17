<?php

namespace Fedale\GridviewBundle\Filter\Applier;

use Fedale\GridviewBundle\Contract\FilterApplierInterface;

class FilterApplierRegistry
{
    /**
     * @var array<string, class-string<FilterApplierInterface>>
     */
    private array $types = [
        'text'     => TextFilterApplier::class,
        'boolean'  => BooleanFilterApplier::class,
        'date'     => DateFilterApplier::class,
        'number'   => NumberFilterApplier::class,
        'choice'   => ChoiceFilterApplier::class,
        'relation' => RelationFilterApplier::class,
    ];

    /**
     * @var array<string, FilterApplierInterface>
     */
    private array $instances = [];

    public function register(string $type, FilterApplierInterface $applier): void
    {
        $this->instances[$type] = $applier;
    }

    public function get(string $type): FilterApplierInterface
    {
        if (isset($this->instances[$type])) {
            return $this->instances[$type];
        }

        if (!isset($this->types[$type])) {
            $known = array_unique(array_merge(array_keys($this->instances), array_keys($this->types)));

            throw new \InvalidArgumentException(sprintf(
                'Unknown filter applier type "%s". Known types: %s.',
                $type,
                implode(', ', $known)
            ));
        }

        $class = $this->types[$type];

        return $this->instances[$type] = new $class();
    }
}
