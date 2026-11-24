<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class NumberColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Number;
    }

    public function decimals(int $decimals): static
    {
        return $this->format(['decimals' => $decimals]);
    }

    /** The lowest and the highest value the write-side control accepts; null for none. */
    public function bounds(int|float|null $min, int|float|null $max): static
    {
        return $this->controlSetting('min', $min)->controlSetting('max', $max);
    }

    /**
     * The decimals the write-side control accepts: its step (2 → 0.01). Unlike
     * NumberType's own `scale`, extra decimals are not rounded away silently.
     */
    public function inputDecimals(int $decimals): static
    {
        return $this->controlSetting('scale', $decimals);
    }
}
