<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class RelationColumn extends AbstractColumnConfig
{
    private ?string $targetClass = null;
    private ?string $choiceLabel = null;
    private bool $required = false;

    /** Whether the current `value` closure was auto-derived (so a resync may replace it). */
    private bool $autoValue = false;

    protected static function columnType(): ColumnType
    {
        return ColumnType::Relation;
    }

    /** FQCN of the related entity (feeds the relation control's `class` option). */
    public function targetClass(string $class): static
    {
        $this->targetClass = $class;

        return $this->syncRelation();
    }

    /** Field on the related entity used as the display/choice label. */
    public function choiceLabel(string $field): static
    {
        $this->choiceLabel = $field;

        return $this->syncRelation();
    }

    /**
     * One-call relation setup: relation filter + relation control (with class and
     * optional choice label) + a default display closure reading the related row.
     */
    public function relation(string $class, ?string $choiceLabel = null, bool $required = false): static
    {
        $this->targetClass = $class;
        if (null !== $choiceLabel) {
            $this->choiceLabel = $choiceLabel;
        }
        $this->required = $required;

        return $this->syncRelation();
    }

    private function syncRelation(): static
    {
        if (null === $this->targetClass) {
            return $this;
        }

        $options = ['class' => $this->targetClass];
        if (null !== $this->choiceLabel) {
            $options['choice_label'] = $this->choiceLabel;
        }

        $this->spec['control'] = ['type' => 'relation', 'required' => $this->required, 'options' => $options];
        $this->spec['filter']  = ['type' => 'relation'];

        // Derive a display closure only when the user has not set an explicit one.
        if ($this->autoValue || !\array_key_exists('value', $this->spec)) {
            $attribute   = $this->spec['attribute'] ?? null;
            $choiceLabel = $this->choiceLabel;
            if (null !== $attribute) {
                $this->spec['value'] = null !== $choiceLabel
                    ? static fn (array $data): mixed => $data[$attribute][$choiceLabel] ?? $data[$attribute]['id'] ?? null
                    : static fn (array $data): mixed => $data[$attribute]['id'] ?? null;
                $this->autoValue = true;
            }
        }

        return $this;
    }
}
