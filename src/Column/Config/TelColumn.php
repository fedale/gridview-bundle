<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class TelColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Tel;
    }
}
