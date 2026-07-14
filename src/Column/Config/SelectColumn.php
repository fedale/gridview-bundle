<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;
use Fedale\GridviewBundle\Form\Control\ControlType;

class SelectColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Select;
    }

    /** Bind the write-side control to a PHP enum (Symfony derives the choices). */
    public function enumClass(string $class): static
    {
        return $this->controlType(ControlType::Enum)->controlOption('class', $class);
    }

    /** Shortcut: enum control + choice filter in one call. */
    public function enum(string $class, bool $required = false): static
    {
        $this->enumClass($class);
        if ($required) {
            $this->required();
        }

        return $this->filterChoice();
    }

    /**
     * A `label => value` choices map used to display the stored value and to feed
     * the choice filter.
     *
     * @param array<string, mixed> $choices
     */
    public function choices(array $choices): static
    {
        return $this->format(['choices' => $choices])->filterChoice();
    }
}
