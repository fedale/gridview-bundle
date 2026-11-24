<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;
use Fedale\GridviewBundle\Form\Control\BooleanDisplay;

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

    /** Draw the write-side control as an on/off switch instead of a checkbox. */
    public function asSwitch(): static
    {
        return $this->controlSetting('display', BooleanDisplay::Switch);
    }
}
