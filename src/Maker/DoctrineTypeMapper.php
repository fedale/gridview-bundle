<?php

namespace Fedale\GridviewBundle\Maker;

use Doctrine\ORM\Mapping\ClassMetadata;
use Fedale\GridviewBundle\Maker\Util\RawPhp;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;

/**
 * Maps Doctrine entity metadata to the gridview column/filter/control/sort
 * vocabulary consumed by {@see \Fedale\GridviewBundle\Column\ColumnFactory}.
 * Stateless: every method takes the metadata/registries it needs as arguments.
 *
 * The keys below are the exact registry keys of the built-in
 * {@see \Fedale\GridviewBundle\Column\Type\ColumnTypeRegistry}, filter appliers
 * and {@see \Fedale\GridviewBundle\Form\Control\ControlTypeRegistry} — verified
 * against the bundle source, not guessed.
 */
final class DoctrineTypeMapper
{
    /** Doctrine field type => gridview column type. */
    private const COLUMN_TYPE_MAP = [
        'string' => 'text',
        'ascii_string' => 'text',
        'text' => 'text',
        'boolean' => 'boolean',
        'integer' => 'number',
        'smallint' => 'number',
        'bigint' => 'number',
        'decimal' => 'number',
        'float' => 'number',
        'date' => 'date',
        'date_immutable' => 'date',
        'datetime' => 'datetime',
        'datetime_immutable' => 'datetime',
        'datetimetz' => 'datetime',
        'datetimetz_immutable' => 'datetime',
        'time' => 'text',
        'time_immutable' => 'text',
        'json' => 'json',
        'array' => 'json',
        'simple_array' => 'json',
        'guid' => 'uuid',
    ];

    /** Gridview column type => default filter type (null = not filterable). */
    private const FILTER_TYPE_MAP = [
        'text' => 'text',
        'boolean' => 'boolean',
        'number' => 'number',
        'date' => 'date',
        'datetime' => 'date',
        'select' => 'choice',
        'json' => null,
        'uuid' => null,
    ];

    /** Gridview column type => default control type (null = excluded from the CRUD form). */
    private const CONTROL_TYPE_MAP = [
        'text' => 'text',
        'boolean' => 'boolean',
        'number' => 'number',
        'date' => 'date',
        'datetime' => 'datetime',
        'select' => 'enum',
        'json' => null,
        'uuid' => 'text',
    ];

    /** Doctrine integer field types get the `integer` control instead of the generic `number` one. */
    private const INTEGER_DOCTRINE_TYPES = ['integer', 'smallint', 'bigint'];

    /** Candidate field names probed on a relation's target entity to build a human label. */
    private const DISPLAY_FIELD_CANDIDATES = ['name', 'title', 'label', 'code'];

    /**
     * Describes every field/association of an entity in a shape convenient for
     * the interactive field picker and for {@see buildColumnPlans()}.
     *
     * @return list<array{name: string, kind: 'field'|'to-one'|'to-many', isIdentifier: bool, inverseSide?: bool, targetClass?: string}>
     */
    public function describeFields(ClassMetadata $metadata): array
    {
        $identifiers = $metadata->getIdentifierFieldNames();
        $fields = [];

        foreach ($metadata->getFieldNames() as $name) {
            $fields[] = [
                'name' => $name,
                'kind' => 'field',
                'isIdentifier' => \in_array($name, $identifiers, true),
            ];
        }

        foreach ($metadata->getAssociationNames() as $name) {
            $fields[] = [
                'name' => $name,
                'kind' => $metadata->isSingleValuedAssociation($name) ? 'to-one' : 'to-many',
                'isIdentifier' => false,
                'inverseSide' => $metadata->isAssociationInverseSide($name),
                'targetClass' => $metadata->getAssociationTargetClass($name),
            ];
        }

        return $fields;
    }

    /**
     * The attribute names pre-selected by default: every scalar field except
     * identifiers, plus owning single-valued associations. Collections and
     * inverse-side associations are opt-in only.
     *
     * @param list<array{name: string, kind: string, isIdentifier: bool, inverseSide?: bool}> $fields
     * @return list<string>
     */
    public function defaultSelection(array $fields): array
    {
        $selected = [];
        foreach ($fields as $field) {
            if ($field['isIdentifier']) {
                continue;
            }
            if ($field['kind'] === 'to-many') {
                continue;
            }
            if ($field['kind'] === 'to-one' && ($field['inverseSide'] ?? false)) {
                continue;
            }
            $selected[] = $field['name'];
        }

        return $selected;
    }

    /**
     * Builds the ordered list of column plans for the selected fields.
     *
     * @param list<array{name: string, kind: string, isIdentifier: bool, inverseSide?: bool, targetClass?: string}> $selectedFields
     * @param array<string, array{label?: string, sortable?: bool, filter?: bool, control?: bool}> $advancedOverrides field name => overrides from the wizard's advanced step
     * @return list<array{attribute: string, label: string, sortable: bool, filter: ?array, control: ?array, value: ?RawPhp}>
     */
    public function buildColumnPlans(array $selectedFields, DoctrineHelper $doctrineHelper, ClassMetadata $metadata, array $advancedOverrides = []): array
    {
        $plans = [];

        foreach ($selectedFields as $field) {
            $override = $advancedOverrides[$field['name']] ?? [];

            $plans[] = match ($field['kind']) {
                'field' => $this->planForScalarField($field, $metadata, $override),
                'to-one' => $this->planForToOneAssociation($field, $doctrineHelper, $override),
                'to-many' => $this->planForToManyAssociation($field, $override),
            };
        }

        return $plans;
    }

    /** @return array{attribute: string, label: string, sortable: bool, filter: ?array, control: ?array, value: ?RawPhp} */
    private function planForScalarField(array $field, ClassMetadata $metadata, array $override): array
    {
        $name = $field['name'];
        $doctrineType = $metadata->getTypeOfField($name) ?? 'string';
        $mapping = $metadata->getFieldMapping($name);
        $isEnum = !empty($mapping['enumType'] ?? null);

        $columnType = $isEnum ? 'select' : (self::COLUMN_TYPE_MAP[$doctrineType] ?? 'text');

        $filter = null;
        if ($override['filter'] ?? true) {
            $filterType = $isEnum ? 'choice' : (self::FILTER_TYPE_MAP[$columnType] ?? null);
            $filter = $filterType !== null ? ['type' => $filterType] : null;
        }

        $control = null;
        if (!$field['isIdentifier'] && ($override['control'] ?? true)) {
            $controlType = self::CONTROL_TYPE_MAP[$columnType] ?? null;
            if (\in_array($doctrineType, self::INTEGER_DOCTRINE_TYPES, true)) {
                $controlType = 'integer';
            }
            if ($isEnum) {
                $control = [
                    'type' => 'enum',
                    'required' => !($mapping['nullable'] ?? false),
                    'options' => ['class' => $mapping['enumType']],
                ];
            } elseif ($controlType !== null) {
                $control = [
                    'type' => $controlType,
                    'required' => !($mapping['nullable'] ?? false),
                ];
            }
        }

        return [
            'attribute' => $name,
            'label' => $override['label'] ?? $this->humanLabel($name),
            'sortable' => $override['sortable'] ?? ($columnType !== 'json'),
            'filter' => $filter,
            'control' => $control,
            'value' => null,
        ];
    }

    /** @return array{attribute: string, label: string, sortable: bool, filter: ?array, control: ?array, value: ?RawPhp} */
    private function planForToOneAssociation(array $field, DoctrineHelper $doctrineHelper, array $override): array
    {
        $name = $field['name'];
        $targetClass = $field['targetClass'];
        $targetMetadata = $doctrineHelper->getMetadata($targetClass);
        $displayField = \is_object($targetMetadata) ? $this->relationDisplayField($targetMetadata) : null;

        $filter = ($override['filter'] ?? true) ? ['type' => 'relation'] : null;

        $control = null;
        if ($override['control'] ?? true) {
            $options = ['class' => new RawPhp('\\' . ltrim($targetClass, '\\') . '::class')];
            if ($displayField !== null) {
                $options['choice_label'] = $displayField;
            }
            $control = ['type' => 'relation', 'required' => false, 'options' => $options];
        }

        $displayExpr = $displayField !== null
            ? \sprintf("\$data['%s']['%s'] ?? \$data['%s']['id'] ?? null", $name, $displayField, $name)
            : \sprintf("\$data['%s']['id'] ?? null", $name);

        return [
            'attribute' => $name,
            'label' => $override['label'] ?? $this->humanLabel($name),
            'sortable' => $override['sortable'] ?? false,
            'filter' => $filter,
            'control' => $control,
            'value' => new RawPhp("fn(array \$data): mixed => {$displayExpr}"),
        ];
    }

    /** @return array{attribute: string, label: string, sortable: bool, filter: ?array, control: ?array, value: ?RawPhp} */
    private function planForToManyAssociation(array $field, array $override): array
    {
        $name = $field['name'];

        return [
            'attribute' => $name,
            'label' => $override['label'] ?? $this->humanLabel($name),
            'sortable' => false,
            'filter' => null,
            'control' => null,
            'value' => new RawPhp(\sprintf(
                "fn(array \$data): mixed => \\is_countable(\$data['%s'] ?? null) ? \\count(\$data['%s']) : null",
                $name,
                $name,
            )),
        ];
    }

    /**
     * The `buildColumns()`-ready array: one entry per plan (only the non-null
     * keys are emitted), plus the trailing action column.
     *
     * @param list<array{attribute: string, label: string, sortable: bool, filter: ?array, control: ?array, value: ?RawPhp}> $plans
     */
    public function columnsArrayFor(array $plans): array
    {
        $columns = [];

        foreach ($plans as $plan) {
            $column = ['attribute' => $plan['attribute'], 'label' => $plan['label']];
            if ($plan['sortable']) {
                $column['sortable'] = true;
            }
            if ($plan['filter'] !== null) {
                $column['filter'] = $plan['filter'];
            }
            if ($plan['control'] !== null) {
                $column['control'] = $plan['control'];
            }
            if ($plan['value'] !== null) {
                $column['value'] = $plan['value'];
            }
            $columns[] = $column;
        }

        $columns[] = ['type' => 'action', 'label' => false];

        return $columns;
    }

    /**
     * @param list<array{attribute: string, sortable: bool}> $plans
     * @return array<string, array{asc: list<string>, desc: list<string>}>
     */
    public function sortMapFor(array $plans, string $alias): array
    {
        $map = [];
        foreach ($plans as $plan) {
            if (!$plan['sortable']) {
                continue;
            }
            $dqlField = "{$alias}.{$plan['attribute']}";
            $map[$plan['attribute']] = ['asc' => [$dqlField], 'desc' => [$dqlField]];
        }

        return $map;
    }

    /**
     * @param list<array{attribute: string, filter: ?array}> $plans
     * @return array<string, array{0: string, 1: string}>
     */
    public function searchFieldsFor(array $plans, string $alias): array
    {
        $searchFields = [];
        foreach ($plans as $plan) {
            if ($plan['filter'] === null) {
                continue;
            }
            $searchFields[$plan['attribute']] = [$plan['filter']['type'], "{$alias}.{$plan['attribute']}"];
        }

        return $searchFields;
    }

    /** Heuristic label field on a relation's target entity, or null when none matches. */
    private function relationDisplayField(ClassMetadata $targetMetadata): ?string
    {
        $fieldNames = $targetMetadata->getFieldNames();
        foreach (self::DISPLAY_FIELD_CANDIDATES as $candidate) {
            if (\in_array($candidate, $fieldNames, true)) {
                return $candidate;
            }
        }

        return null;
    }

    public function humanLabel(string $attribute): string
    {
        $words = preg_split('/(?=[A-Z])|_/', $attribute, -1, \PREG_SPLIT_NO_EMPTY);

        return ucfirst(strtolower(implode(' ', $words)));
    }
}
