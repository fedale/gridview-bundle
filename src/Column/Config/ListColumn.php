<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class ListColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::List;
    }
}
