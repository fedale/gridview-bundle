<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;
use Fedale\GridviewBundle\Filter\FilterType;
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

    /**
     * Shortcut: enum control + choice filter in one call. The filter is populated
     * from the enum's own cases — a bare `choice` filter would render an empty
     * `<select>`, since the filter type has no way to guess the value set.
     */
    public function enum(string $class, bool $required = false): static
    {
        $this->enumClass($class);
        if ($required) {
            $this->required();
        }

        return $this->choiceFilter(self::enumChoices($class));
    }

    /**
     * A `label => value` choices map used to display the stored value and to feed
     * the choice filter.
     *
     * @param array<string, mixed> $choices
     */
    public function choices(array $choices): static
    {
        return $this->format(['choices' => $choices])->choiceFilter($choices);
    }

    /**
     * `['Label' => backing value]` for a backed enum. A case's own `label()` wins
     * when the enum defines one (a plain string or a translation key, as the
     * common Symfony idiom has it); otherwise the case name is the label.
     *
     * @return array<string, string|int>
     */
    private static function enumChoices(string $class): array
    {
        if (!is_a($class, \BackedEnum::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Column enum() expects a backed enum class, "%s" given.',
                $class,
            ));
        }

        $choices = [];
        foreach ($class::cases() as $case) {
            $label = method_exists($case, 'label') ? (string) $case->label() : $case->name;
            $choices[$label] = $case->value;
        }

        return $choices;
    }

    /**
     * Declare the choice filter with its value set, preserving any filter options
     * already set on the column.
     *
     * @param array<string, mixed> $choices
     */
    private function choiceFilter(array $choices): static
    {
        $filter = \is_array($this->spec['filter'] ?? null) ? $this->spec['filter'] : [];
        $filter['type'] = FilterType::Choice->value;
        $filter['options'] = ['choices' => $choices, ...($filter['options'] ?? [])];

        return $this->filter($filter);
    }
}
