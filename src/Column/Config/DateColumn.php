<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class DateColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Date;
    }

    /** A PHP date() format string; bypasses locale-aware formatting. */
    public function pattern(string $pattern): static
    {
        return $this->format(['pattern' => $pattern]);
    }
}
