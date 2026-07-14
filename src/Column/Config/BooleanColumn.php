<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class BooleanColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Boolean;
    }

    /** Override the glyphs/strings shown for the true and false states. */
    public function labels(string $true, string $false): static
    {
        return $this->format(['true' => $true, 'false' => $false]);
    }
}
