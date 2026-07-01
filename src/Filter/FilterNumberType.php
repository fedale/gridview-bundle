<?php

namespace Fedale\GridviewBundle\Filter;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Numeric filter. Two modes:
 *
 *  - single input (default): one text box in which the whole comparison is typed
 *    inline — a plain number ("34" → equals), an operator expression (">34",
 *    ">=34", "<34", "<=34", "!=34"/"<>34", "=34"), or an inclusive range
 *    ("btw 10 and 20", "10 -> 20", "10..20", legacy "10-20"). Parsed by
 *    {@see \Fedale\GridviewBundle\Filter\Applier\NumberFilterApplier}.
 *  - range (`single_input => false`): the classic from/to (Min/Max) pair. Each
 *    bound is still a plain text input so it also accepts the operator/range
 *    syntax above.
 */
class FilterNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Single-input mode is a leaf (compound => false): nothing to add, the
        // field itself holds the scalar the applier parses.
        if ($options['single_input']) {
            return;
        }

        // Plain text inputs (not type=number) so the bounds also accept the
        // operator/range syntax handled by NumberFilterApplier (">5", "1-5", …).
        $builder
            ->add('from', TextType::class, [
                'required' => false,
                'label'    => false,
                'attr'     => [
                    'type'        => 'text',
                    'inputmode'   => 'text',
                    'placeholder' => $options['from_placeholder'],
                ],
            ])
            ->add('to', TextType::class, [
                'required' => false,
                'label'    => false,
                'attr'     => [
                    'type'        => 'text',
                    'inputmode'   => 'text',
                    'placeholder' => $options['to_placeholder'],
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required'         => false,
            'label'            => false,
            'single_input'     => true,
            'placeholder'      => '>10, 5..20, =7',
            'from_placeholder' => 'Min',
            'to_placeholder'   => 'Max',
            // A single-input field is a leaf; the range pair is compound.
            'compound'         => static fn (Options $o): bool => !$o['single_input'],
            'attr'             => static fn (Options $o): array => $o['single_input']
                ? [
                    'class'       => 'gv-number-filter gv-number-filter--single',
                    'type'        => 'text',
                    'inputmode'   => 'text',
                    'placeholder' => $o['placeholder'],
                ]
                : ['class' => 'gv-number-filter'],
        ]);

        $resolver->setAllowedTypes('single_input', 'bool');
        $resolver->setAllowedTypes('placeholder', 'string');
        $resolver->setAllowedTypes('from_placeholder', 'string');
        $resolver->setAllowedTypes('to_placeholder', 'string');
    }
}
