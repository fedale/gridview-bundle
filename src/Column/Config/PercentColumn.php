<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class PercentColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Percent;
    }

    public function decimals(int $decimals): static
    {
        return $this->format(['decimals' => $decimals]);
    }
}
