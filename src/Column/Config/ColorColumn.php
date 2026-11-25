<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class ColorColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Color;
    }

    /**
     * Edit it with a "No color" checkbox beside the colour input, which always holds one: the
     * field can be emptied.
     */
    public function nullable(bool $nullable = true): static
    {
        return $this->controlSetting('nullable', $nullable);
    }
}
