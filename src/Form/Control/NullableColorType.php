<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A `color` control that may hold no colour (`nullable`): the browser's colour input, which
 * always holds one, and a "No color" checkbox beside it. Its data is a colour as `#rrggbb` in
 * lower case, or null when the checkbox is checked. It works without JavaScript; the
 * gridview-color controller unchecks the box once a colour is picked.
 */
final class NullableColorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('color', ColorType::class, [
                'label'          => false,
                'required'       => false,
                'html5'          => true,
                // The colour's errors are the field's.
                'error_bubbling' => true,
                'attr'           => ['data-gridview-color-target' => 'color'],
            ])
            ->add('none', CheckboxType::class, [
                'label'              => 'color.none',
                'translation_domain' => 'GridviewBundle',
                'required'           => false,
                'attr'               => ['data-gridview-color-target' => 'none'],
            ]);
        $builder->setDataMapper(new NullableColorMapper());
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $attr = \is_array($view->vars['attr'] ?? null) ? $view->vars['attr'] : [];
        $controllers = \is_string($attr['data-controller'] ?? null) ? $attr['data-controller'] : '';
        $view->vars['attr'] = ['class' => trim((\is_string($attr['class'] ?? null) ? $attr['class'] : '') . ' gv-color')] + $attr;
        $view->vars['attr']['data-controller'] = trim($controllers . ' gridview-color');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound'   => true,
            'empty_data' => null,
            // A colour is one value: its data is not an array of the children's.
            'data_class' => null,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'gv_color';
    }
}
