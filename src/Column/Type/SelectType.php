<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Single choice from an enum — displays the option label for a stored value.
 * Options: `choices` as a `label => value` map (Symfony convention); the value
 * is mapped back to its label for display. Unknown values pass through.
 */
class SelectType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'select';
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->labelFor($value, $options['choices'] ?? []);
    }

    /** Resolve a stored value to its display label from a `label => value` choices map. */
    protected function labelFor(mixed $value, array $choices): mixed
    {
        $byValue = \array_flip($choices);

        return $byValue[$value] ?? $value;
    }

    public function inferFilterType(): ?string
    {
        return 'choice';
    }

    public function inferControlType(): ?string
    {
        return 'choice';
    }
}
