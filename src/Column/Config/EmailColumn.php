<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class EmailColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Email;
    }
}
