<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Maps a column `control.type` to the Symfony FormType class used to render the
 * write-side field. Mirrors {@see \Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry}
 * but resolves FormType classes (resolved later by the form factory) instead of
 * applier instances.
 *
 * Note the intentional divergence from the filter side: a `relation` control
 * uses EntityType (binding managed entities) whereas a `relation` filter uses a
 * scalar ChoiceType. They are kept as separate entries on purpose.
 */
class ControlTypeRegistry
{
    /**
     * @var array<string, class-string>
     */
    private array $types = [
        'text'     => TextType::class,
        'number'   => NumberType::class,
        'date'     => DateType::class,
        'boolean'  => CheckboxType::class,
        'relation' => EntityType::class,
        'choice'   => ChoiceType::class,
        'hidden'   => HiddenType::class,
        'html'     => TextareaType::class,
    ];

    /** Register or override a control type. */
    public function register(string $type, string $formTypeClass): void
    {
        $this->types[$type] = $formTypeClass;
    }

    /**
     * @return class-string
     */
    public function get(string $type): string
    {
        if (!isset($this->types[$type])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown control type "%s". Known types: %s.',
                $type,
                implode(', ', array_keys($this->types))
            ));
        }

        return $this->types[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }
}
