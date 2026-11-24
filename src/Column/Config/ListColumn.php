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

    /**
     * Edit the list as free text labels (a `tags` control): `$suggestions` are offered as
     * the user types, and with `$restrict` they are the only labels accepted. `$max` counts
     * the labels and `$maxLength` measures each one; null: no limit.
     *
     * @param list<string> $suggestions
     */
    public function tags(array $suggestions = [], bool $restrict = false, ?int $max = null, ?int $maxLength = null): static
    {
        $this->controlType(ControlType::Tags)
            ->controlSetting('suggestions', $suggestions)
            ->controlSetting('restrict', $restrict);
        if ($max !== null) {
            $this->controlSetting('max', $max);
        }

        return $maxLength !== null ? $this->controlSetting('maxLength', $maxLength) : $this;
    }
}
