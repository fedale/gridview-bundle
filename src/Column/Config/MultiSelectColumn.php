<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class MultiSelectColumn extends SelectColumn
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::MultiSelect;
    }
}
