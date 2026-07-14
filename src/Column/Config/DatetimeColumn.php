<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class DatetimeColumn extends DateColumn
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Datetime;
    }
}
