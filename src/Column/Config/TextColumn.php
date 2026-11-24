<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class TextColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Text;
    }

    /** The most characters the write-side control accepts (its `maxlength`). */
    public function maxLength(int $maxLength): static
    {
        return $this->controlSetting('maxLength', $maxLength);
    }
}
