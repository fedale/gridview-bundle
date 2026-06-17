<?php

namespace Fedale\GridviewBundle\Form\Control;

/**
 * Normalizes a column's `control` spec into a canonical array
 * `['type' => string, 'required' => bool, 'options' => array, 'modes' => ?array,
 *   'unique' => true|string[]|null, 'constraints' => Constraint[]]`.
 *
 * Mirrors {@see \Fedale\GridviewBundle\Column\ColumnFactory::normalizeFilter()}:
 * an explicit `control.type` always wins, otherwise the column's root data type
 * is inherited (falling back to "text" for data types without a control mapping).
 */
class ControlResolver
{
    /** Data types whose name doubles as a control type. */
    private const INHERITABLE = ['text', 'number', 'date', 'boolean', 'relation', 'choice'];

    /**
     * @return array{type: string, required: bool, requiredMessage: ?string, options: array, modes: ?array, unique: ?array, constraints: array}
     */
    public function resolve(mixed $control, string $dataType): array
    {
        $inherited = \in_array($dataType, self::INHERITABLE, true) ? $dataType : 'text';

        if (\is_string($control)) {
            $control = ['type' => $control];
        } elseif (!\is_array($control)) {
            // `control => true` (or any truthy scalar) inherits the root type.
            $control = [];
        }

        $type     = $control['type'] ?? $inherited;
        $required = (bool) ($control['required'] ?? false);
        $options  = $control['options'] ?? [];

        if ($type === 'relation' && !isset($options['class'])) {
            throw new \InvalidArgumentException(
                'A "relation" control requires options.class (the related entity FQCN). '
                . 'Optionally provide options.choice_label and options.multiple.'
            );
        }

        // modes: list of CRUD modes where the control is active (null = all).
        $modes = $control['modes'] ?? null;
        if ($modes !== null && !\is_array($modes)) {
            $modes = [$modes];
        }

        return [
            'type'            => $type,
            'required'        => $required,
            'requiredMessage' => $control['requiredMessage'] ?? null,
            'options'         => $options,
            'modes'           => $modes,
            'unique'          => $this->normalizeUnique($control),
            'constraints'     => $control['constraints'] ?? [],
        ];
    }

    /**
     * Normalizes the `unique` spec to `['fields' => ?string[], 'message' => ?string]`
     * or null. `fields === null` means "use this column's attribute" (resolved by
     * the form builder, which knows the attribute).
     *
     * Accepts: true | 'field' | ['f1','f2'] | ['fields' => [...], 'message' => '...'].
     * A standalone `uniqueMessage` applies to the shorthand forms.
     *
     * @return array{fields: ?array, message: ?string}|null
     */
    private function normalizeUnique(array $control): ?array
    {
        $unique  = $control['unique'] ?? null;
        $message = $control['uniqueMessage'] ?? null;

        if ($unique === null || $unique === false) {
            return null;
        }
        if ($unique === true) {
            return ['fields' => null, 'message' => $message];
        }
        if (\is_string($unique)) {
            return ['fields' => [$unique], 'message' => $message];
        }
        if (\is_array($unique)) {
            if (isset($unique['fields'])) {
                return [
                    'fields'  => (array) $unique['fields'],
                    'message' => $unique['message'] ?? $message,
                ];
            }

            return ['fields' => array_values($unique), 'message' => $message];
        }

        return null;
    }
}
