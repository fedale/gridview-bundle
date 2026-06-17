<?php

namespace Fedale\GridviewBundle\Filter;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ChoiceList\View\ChoiceGroupView;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FilterRelationType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required'     => false,
            'placeholder'  => '',
            'ajax_url'     => null,
            'searchable'   => false,
            'option_label' => 'name',
            'option_value' => 'id',
        ]);

        $resolver->setAllowedTypes('ajax_url', ['null', 'string']);
        $resolver->setAllowedTypes('searchable', ['bool']);
        $resolver->setAllowedTypes('option_label', 'string');
        $resolver->setAllowedTypes('option_value', 'string');
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $attr = $view->vars['attr'] ?? [];

        $existing = $attr['data-controller'] ?? '';
        $attr['data-controller'] = trim($existing . ' gridview-relation-filter');

        if ($options['ajax_url']) {
            $attr['data-gridview-relation-filter-ajax-url-value']     = $options['ajax_url'];
            $attr['data-gridview-relation-filter-option-label-value'] = $options['option_label'];
            $attr['data-gridview-relation-filter-option-value-value'] = $options['option_value'];
            $attr['data-gridview-relation-filter-searchable-value']   = 'true';
        } elseif ($options['searchable']) {
            $attr['data-gridview-relation-filter-searchable-value'] = 'true';
        }

        $view->vars['attr'] = $attr;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if ($options['ajax_url']) {
            return;
        }

        $choices = [];
        $allChoices = array_merge(
            $view->vars['preferred_choices'] ?? [],
            $view->vars['choices'] ?? []
        );
        foreach ($allChoices as $choice) {
            if ($choice instanceof ChoiceGroupView) {
                foreach ($choice->choices as $c) {
                    $choices[] = ['v' => $c->value, 'l' => $c->label];
                }
            } else {
                $choices[] = ['v' => $choice->value, 'l' => $choice->label];
            }
        }

        $attr = $view->vars['attr'] ?? [];
        $attr['data-gridview-relation-filter-choices-value']  = json_encode($choices);
        $attr['data-gridview-relation-filter-selected-value'] = json_encode(
            array_values((array) ($view->vars['value'] ?? []))
        );
        $view->vars['attr']              = $attr;
        $view->vars['choices']           = [];
        $view->vars['preferred_choices'] = [];
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
