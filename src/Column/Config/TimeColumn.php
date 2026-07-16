<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class TimeColumn extends DateColumn
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Time;
    }
}
