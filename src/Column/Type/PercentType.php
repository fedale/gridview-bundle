<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Percentage — number suffixed with "%". Options: `scale` (multiplier applied to
 * the raw value; default 1, i.e. value is already a percentage; use 100 for 0..1
 * fractions), `decimals` (default 0).
 */
class PercentType extends NumberType
{
    public function getName(): string
    {
        return 'percent';
    }

    public function getParent(): ?string
    {
        return 'number';
    }

    public function getDefaultOptions(): array
    {
        return ['scale' => 1, 'decimals' => 0, 'decimalSep' => ',', 'thousandsSep' => '.'];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $scaled = (float) $value * (float) ($options['scale'] ?? 1);

        return parent::format($scaled, $options, $column) . '%';
    }
}
