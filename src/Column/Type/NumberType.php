<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Numeric value — formatted with thousands/decimal separators and right-aligned
 * (`gv-num`). Options: `decimals`, `decimalSep`, `thousandsSep`. Defaults follow
 * the it-IT convention (1.234,56) used by the host app.
 */
class NumberType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'number';
    }

    public function getDefaultOptions(): array
    {
        return ['decimals' => 0, 'decimalSep' => ',', 'thousandsSep' => '.'];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return \number_format(
            (float) $value,
            (int) ($options['decimals'] ?? 0),
            (string) ($options['decimalSep'] ?? ','),
            (string) ($options['thousandsSep'] ?? '.')
        );
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->markup('<span class="gv-num">' . $this->esc($value) . '</span>');
    }

    public function inferFilterType(): ?string
    {
        return 'number';
    }

    public function inferControlType(): ?string
    {
        return 'number';
    }
}
