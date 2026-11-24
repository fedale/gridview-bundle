<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A row of a `collection` control built from control specs, one field each: the same
 * controls as anywhere else, through ControlBuilder. A spec may carry its `label`.
 *
 *     'entry' => ['phone' => ['type' => 'tel', 'label' => 'Phone'], 'note' => ['type' => 'text']]
 */
final class ControlRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ControlBuilder $controls */
        $controls = $options['control_builder'];
        /** @var array<string, bool|string|array<string, mixed>|ControlType> $entry */
        $entry = $options['controls'];
        foreach ($entry as $name => $control) {
            $label = \is_array($control) && \is_string($control['label'] ?? null) ? $control['label'] : null;
            if (\is_array($control)) {
                unset($control['label']);
            }
            $controls->add($builder, $name, $control, $label);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['controls', 'control_builder'])
            ->setAllowedTypes('controls', 'array')
            ->setAllowedTypes('control_builder', ControlBuilder::class)
            ->setDefaults(['label' => false, 'error_bubbling' => false]);
    }

    public function getBlockPrefix(): string
    {
        return 'gv_control_row';
    }
}
