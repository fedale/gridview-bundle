<?php

namespace Fedale\GridviewBundle\Form;

use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Contract\GridCrudHandlerInterface;
use Fedale\GridviewBundle\Contract\GridFormBuilderInterface;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\I18n\GridviewI18nCatalog;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Builds a Symfony form from a grid's columns (write side), mirroring the
 * form-building half of {@see SearchForm}: each column with a `control`
 * contributes a field via {@see \Fedale\GridviewBundle\Column\AbstractColumn::buildControl()}.
 */
class GridFormBuilder implements GridFormBuilderInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private ControlTypeRegistry $controlTypeRegistry,
        private GridviewI18nCatalog $i18nCatalog,
    ) {
    }

    public function build(string $dataClass, iterable $columns, ?object $data = null, array $options = []): FormInterface
    {
        $mode = $options['mode'] ?? null;

        // Materialize once: we iterate twice (unique specs, then fields) and the
        // caller may pass a one-shot iterable.
        $columns = \is_array($columns) ? $columns : iterator_to_array($columns);

        // Keep only the controls active for this mode (control.modes filter).
        $active = [];
        foreach ($columns as $column) {
            if (!$column instanceof ColumnInterface || $column->getControl() === null) {
                continue;
            }
            $modes = $column->getControl()['modes'] ?? null;
            if ($mode !== null && $modes !== null && !\in_array($mode, $modes, true)) {
                continue;
            }
            // Honour the column's per-context `active` switch: add/clone map to the
            // `create` context, edit to `update`.
            if ($mode !== null) {
                $context = $mode === GridCrudHandlerInterface::MODE_EDIT ? 'update' : 'create';
                if (!$column->isActiveIn($context)) {
                    continue;
                }
            }
            $active[] = $column;
        }

        // Pass 1: turn each column's `unique` spec into a root-level UniqueEntity
        // constraint (a class constraint validating the bound entity).
        $rootConstraints = $options['form_options']['constraints'] ?? [];
        foreach ($active as $column) {
            $unique = $column->getControl()['unique'] ?? null;
            if ($unique === null) {
                continue;
            }
            $entityOptions = [
                'fields'      => $unique['fields'] ?? [$column->getAttribute()],
                'entityClass' => $dataClass,
            ];
            if (!empty($unique['message'])) {
                $entityOptions['message'] = $unique['message'];
            }
            $rootConstraints[] = new UniqueEntity($entityOptions);
        }

        // Resolve labels against the client i18n domain (same as the grid headers),
        // so column labels expressed as translation keys (e.g. `col.customer.code`)
        // are translated in the form too. Plain-text labels pass through unchanged.
        // Caller-provided form_options can still override the domain.
        $formOptions = array_merge([
            'data_class'         => $dataClass,
            'method'             => 'POST',
            'translation_domain' => $this->i18nCatalog->getClientDomain(),
        ], $options['form_options'] ?? []);
        if ($rootConstraints !== []) {
            $formOptions['constraints'] = $rootConstraints;
        }

        $builder = $this->formFactory->createNamedBuilder(
            $options['name'] ?? 'gridform',
            FormType::class,
            $data,
            $formOptions
        );

        // Pass 2: add the active fields.
        foreach ($active as $column) {
            $column->buildControl($builder, $this->controlTypeRegistry);
        }

        if ($options['submit'] ?? true) {
            $builder->add('save', SubmitType::class, [
                'label' => $options['submit_label'] ?? 'Save',
                'attr'  => ['class' => 'gv-btn gv-btn-primary'],
            ]);
        }

        return $builder->getForm();
    }

    /**
     * Builds the bulk batch-update form (not bound to an entity): for each column
     * flagged `batchUpdate`, an `enable_<attr>` checkbox plus the value field
     * (reusing the control type, forced optional). On submit only the fields whose
     * enable checkbox is checked are applied.
     *
     * @param iterable<ColumnInterface> $columns
     */
    public function buildBatchForm(iterable $columns, array $options = []): FormInterface
    {
        $builder = $this->formFactory->createNamedBuilder(
            $options['name'] ?? 'batch',
            FormType::class,
            null,
            ['method' => 'POST', 'translation_domain' => $this->i18nCatalog->getClientDomain()]
        );

        foreach ($columns as $column) {
            if (!$column instanceof ColumnInterface || !$column->isBatchUpdate() || $column->getControl() === null) {
                continue;
            }
            $attribute = $column->getAttribute();
            $control   = $column->getControl();

            $builder->add('enable_' . $attribute, CheckboxType::class, [
                'required' => false,
                'label'    => $column->getLabel() ?? $attribute,
            ]);

            $fieldOptions = $control['options'] ?? [];
            $fieldOptions['required'] = false;
            $fieldOptions['label'] = false;
            $builder->add($attribute, $this->controlTypeRegistry->get($control['type']), $fieldOptions);
        }

        if ($options['submit'] ?? true) {
            $builder->add('apply', SubmitType::class, [
                'label' => $options['submit_label'] ?? 'Applica',
                'attr'  => ['class' => 'gv-btn gv-btn-primary'],
            ]);
        }

        return $builder->getForm();
    }

    public function controlAttributes(iterable $columns): array
    {
        $attributes = [];
        foreach ($columns as $column) {
            if ($column instanceof ColumnInterface
                && $column->getControl() !== null
                && $column->getAttribute() !== null
            ) {
                $attributes[] = $column->getAttribute();
            }
        }

        return $attributes;
    }
}
