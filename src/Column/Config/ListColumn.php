<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;
use Fedale\GridviewBundle\Form\Control\ControlType;

class ListColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::List;
    }

    /**
     * Edit the list as repeatable rows (a `collection` control), each built from the
     * control specs of `$entry` keyed by field name (a spec may carry its `label`).
     * `$min` and `$max` count the rows, 0 meaning no limit.
     *
     * @param array<string, mixed> $entry
     */
    public function rows(array $entry, int $min = 0, int $max = 0, bool $inline = false): static
    {
        return $this->controlType(ControlType::Collection)
            ->controlSetting('entry', $entry)
            ->controlSetting('min', $min)
            ->controlSetting('max', $max)
            ->controlSetting('inline', $inline);
    }
}
