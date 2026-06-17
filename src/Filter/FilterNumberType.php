<?php

namespace Fedale\GridviewBundle\Filter;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FilterNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
            'from_placeholder' => 'Min',
            'to_placeholder'   => 'Max',
            'attr'             => ['class' => 'gv-number-filter'],
        ]);

        $resolver->setAllowedTypes('from_placeholder', 'string');
        $resolver->setAllowedTypes('to_placeholder', 'string');
    }
}
