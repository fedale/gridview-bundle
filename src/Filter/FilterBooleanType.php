<?php

namespace Fedale\GridviewBundle\Filter;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class FilterBooleanType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'true_label'         => 'Yes',
            'false_label'        => 'No',
            'placeholder'        => '–',
            'translation_domain' => false,
        ]);

        $resolver->setAllowedTypes('true_label', 'string');
        $resolver->setAllowedTypes('false_label', 'string');

        $resolver->setNormalizer('choices', function (Options $options, mixed $value): array {
            return [
                $options['true_label']  => '1',
                $options['false_label'] => '0',
            ];
        });
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
