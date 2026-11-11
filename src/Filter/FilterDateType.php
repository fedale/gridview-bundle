<?php

namespace Fedale\GridviewBundle\Filter;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FilterDateType extends AbstractType
{
    /**
     * No `locale`: the picker follows the page language unless the column sets
     * one in its clientOptions. No `minDate`/`maxDate` either: any date can be
     * picked unless the column bounds it.
     */
    private const CLIENT_DEFAULTS = [
        'mode'      => 'range',
        'altFormat' => 'd/m/Y',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', TextType::class, [
                'required' => false,
                'label'    => false,
                'attr'     => ['type' => 'date', 'placeholder' => $options['from_placeholder']],
                // The placeholders are keys of the bundle's catalog (a plain text
                // the catalog lacks is shown as it is).
                'translation_domain' => 'GridviewBundle',
            ])
            ->add('to', TextType::class, [
                'required' => false,
                'label'    => false,
                'attr'     => ['type' => 'date', 'placeholder' => $options['to_placeholder']],
                'translation_domain' => 'GridviewBundle',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required'         => false,
            'label'            => false,
            'from_placeholder' => 'filter.date_from',
            'to_placeholder'   => 'filter.date_to',
            'client_options'   => [],
            'attr'             => ['class' => 'gv-date-filter'],
        ]);

        $resolver->setAllowedTypes('from_placeholder', 'string');
        $resolver->setAllowedTypes('to_placeholder', 'string');
        $resolver->setAllowedTypes('client_options', 'array');

        // Merge client_options with defaults and inject Stimulus data attributes onto the wrapper div
        $resolver->setNormalizer('attr', function (Options $options, array $value): array {
            $clientOpts = array_merge(self::CLIENT_DEFAULTS, $options['client_options']);

            return array_merge([
                'data-controller'                         => 'gridview-date-filter',
                'data-gridview-date-filter-options-value' => json_encode($clientOpts),
            ], $value);
        });
    }
}
