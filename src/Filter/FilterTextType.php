<?php

namespace Fedale\GridviewBundle\Filter;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class FilterTextType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'placeholder' => '',
            'attr'        => [],
        ]);

        $resolver->setAllowedTypes('placeholder', 'string');

        // Mirror the NG TextFilter 'placeholder' arg by injecting it into the
        // rendered input's attributes (unless the caller already set one).
        $resolver->setNormalizer('attr', function (Options $options, array $value): array {
            if ($options['placeholder'] !== '' && !isset($value['placeholder'])) {
                $value['placeholder'] = $options['placeholder'];
            }

            return $value;
        });
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
