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
}
