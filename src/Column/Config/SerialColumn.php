<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

/** Structural row-number column. */
class SerialColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Serial;
    }
}
