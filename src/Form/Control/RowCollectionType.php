<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The `collection` control: repeatable rows, added and removed in the page by the
 * gridview-collection controller (the gv_collection blocks of the form theme). Each row is
 * an entry of any type: a ControlRowType built from control specs, or a type of the host's.
 *
 * `min` and `max` (0: no limit) guide the buttons; the server checks them (a Count
 * constraint, see ControlBuilder). A row added and left blank is dropped.
 */
final class RowCollectionType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['prototype_name'] = $options['prototype_name'];
        $view->vars['collection_min'] = $options['min'];
        $view->vars['collection_max'] = $options['max'];
        $view->vars['collection_inline'] = $options['inline'];
        $view->vars['add_label'] = $options['add_label'];
        $view->vars['add_label_translation_parameters'] = $options['add_label_translation_parameters'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'allow_add'                        => true,
                'allow_delete'                     => true,
                'delete_empty'                     => static fn(mixed $row): bool => self::isBlank($row),
                'error_bubbling'                   => false,
                'min'                              => 0,
                'max'                              => 0,
                // Each row on one line, its inputs side by side.
                'inline'                           => false,
                // The text of the add button, in the form's translation domain; the
                // bundle's own "Add a row" without it.
                'add_label'                        => null,
                'add_label_translation_parameters' => [],
            ])
            ->setAllowedTypes('min', 'int')
            ->setAllowedTypes('max', 'int')
            ->setAllowedTypes('inline', 'bool')
            ->setAllowedTypes('add_label', ['null', 'string'])
            ->setAllowedTypes('add_label_translation_parameters', 'array');
    }

    public function getParent(): string
    {
        return CollectionType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'gv_collection';
    }

    /**
     * A row whose every value is empty (null, '', false, or an array of such).
     */
    public static function isBlank(mixed $row): bool
    {
        if (\is_array($row)) {
            foreach ($row as $value) {
                if (!self::isBlank($value)) {
                    return false;
                }
            }

            return true;
        }

        return $row === null || $row === '' || $row === false;
    }
}
