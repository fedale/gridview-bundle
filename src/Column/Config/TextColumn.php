<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;
use Fedale\GridviewBundle\Form\Control\ControlType;

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

    /**
     * Edit the column as a password: never prefilled by the browser, optionally asked
     * twice (`$confirm`), with a strength meter and a show/hide eye. `$minLength` is
     * the meter's threshold and, when the form validates, the shortest accepted.
     */
    public function password(bool $confirm = false, bool $strengthMeter = false, bool $revealToggle = false, ?int $minLength = null): static
    {
        $this->controlType(ControlType::Password)
            ->controlSetting('confirm', $confirm)
            ->controlSetting('strengthMeter', $strengthMeter)
            ->controlSetting('revealToggle', $revealToggle);

        return $minLength !== null ? $this->controlSetting('minLength', $minLength) : $this;
    }
}
