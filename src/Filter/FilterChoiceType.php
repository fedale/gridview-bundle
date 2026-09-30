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
        // `required: false` + an empty placeholder mirror FilterRelationType: a
        // filter is never mandatory, and without the empty option the user could
        // pick a value but never get back to "no filter".
        $resolver->setDefaults([
            'choices'     => [],
            'required'    => false,
            'placeholder' => '',
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
