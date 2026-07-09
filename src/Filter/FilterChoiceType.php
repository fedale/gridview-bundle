<?php

namespace Fedale\GridviewBundle\Filter;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class FilterChoiceType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        // No sensible generic default exists — the caller (column `filter.options.choices`)
        // must supply the real choice list, e.g. from a backing PHP enum.
        $resolver->setDefaults([
            'choices' => [],
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
