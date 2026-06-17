<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Multiple choices — an array of values, each mapped to its option label and
 * rendered as a comma-separated list. Options: `choices`, `separator`.
 */
class MultiSelectType extends SelectType
{
    public function getName(): string
    {
        return 'multiSelect';
    }

    public function getParent(): ?string
    {
        return 'select';
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        $choices = $options['choices'] ?? [];
        $labels = [];
        foreach ((array) $value as $item) {
            $labels[] = $this->labelFor($item, $choices);
        }

        return \implode($options['separator'] ?? ', ', $labels);
    }
}
