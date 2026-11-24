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
    /**
     * Data types whose name doubles as a control type, so a column declaring a
     * `control` without an explicit `type` inherits its display data type
     * (e.g. `type => 'email'` → EmailType control). `money` and `currency` are
     * both listed on purpose, each pointing at its own matching control (the
     * MoneyType amount editor vs. the CurrencyType code picker) — see
     * {@see \Fedale\GridviewBundle\Column\Type\MoneyType} /
     * {@see \Fedale\GridviewBundle\Column\Type\CurrencyType}.
     */
    private const INHERITABLE = [
        'text', 'number', 'date', 'boolean', 'relation', 'choice',
        'email', 'url', 'percent', 'datetime', 'time',
        'money', 'currency', 'color', 'country', 'language', 'locale',
        'timezone', 'hidden', 'tel',
    ];

    /**
     * The `display` values each control accepts; a control not listed takes none.
     */
    private const DISPLAYS = [
        'boolean' => BooleanDisplay::class,
        'choice'  => ChoiceDisplay::class,
    ];

    /**
     * Controls whose input is text, so `maxLength` becomes the `maxlength` attribute.
     */
    private const TEXT_CONTROLS = ['text', 'textarea', 'html', 'email', 'url', 'tel', 'password'];

    /**
     * Controls whose input is a number, so `min` and `max` become attributes.
     */
    private const NUMBER_CONTROLS = ['number', 'integer'];

    /**
     * @return array{type: string, required: bool, requiredMessage: ?string, options: array, modes: ?array, unique: ?array, constraints: array, upload: ?callable, display: ?string, maxLength: ?int, min: int|float|null, max: int|float|null, scale: ?int, confirm: bool, strengthMeter: bool, revealToggle: bool, minLength: ?int, url: ?string, labels: ?callable, dependsOn: ?string, dependsParam: ?string, dependsValue: string|int|null, validate: bool}
     */
    public function resolve(mixed $control, string $dataType): array
    {
        $inherited = \in_array($dataType, self::INHERITABLE, true) ? $dataType : 'text';

        if ($control instanceof \BackedEnum) {
            $control = (string) $control->value;
        }
        if (\is_string($control)) {
            $control = ['type' => $control];
        } elseif (!\is_array($control)) {
            // `control => true` (or any truthy scalar) inherits the root type.
            $control = [];
        }

        // The type and the display may be given as their enums (ControlType,
        // ChoiceDisplay…): a control built outside a column comes here unwrapped by
        // nobody else.
        foreach (['type', 'display'] as $key) {
            if (($control[$key] ?? null) instanceof \BackedEnum) {
                $control[$key] = $control[$key]->value;
            }
        }

        $type     = $control['type'] ?? $inherited;
        $required = (bool) ($control['required'] ?? false);
        $options  = $control['options'] ?? [];

        $url = $this->setting($type, $control, 'url', ['relation'], 'is_string');
        if ($type === 'relation' && $url === null && !isset($options['class'])) {
            throw new \InvalidArgumentException(
                'A "relation" control requires options.class (the related entity FQCN) or a url (a remote lookup). '
                . 'Optionally provide options.choice_label and options.multiple.'
            );
        }
        $labels = $this->setting($type, $control, 'labels', ['relation'], 'is_callable');
        if ($url !== null && $labels === null) {
            throw new \InvalidArgumentException('A remote "relation" control requires labels, a callable giving the labels of the ids it is given.');
        }
        $dependsOn = $this->setting($type, $control, 'dependsOn', ['relation'], 'is_string');
        $dependsParam = $this->setting($type, $control, 'dependsParam', ['relation'], 'is_string');
        if ($dependsOn !== null && ($url === null || $dependsParam === null)) {
            throw new \InvalidArgumentException(sprintf('The "relation" control depending on "%s" requires a url and a dependsParam, the query parameter its value goes in.', $dependsOn));
        }

        if ($type === 'enum' && !isset($options['class'])) {
            throw new \InvalidArgumentException(
                'An "enum" control requires options.class (the PHP enum FQCN). '
                . 'Symfony derives the choices from the enum cases and binds the enum instance.'
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
            // Phase-2 hand-off for `media` controls: the host app's callable that
            // stores the uploaded file and populates the entity (see ControlBuilder).
            'upload'          => $control['upload'] ?? null,
            // The bundle's own settings, turned into form options by ControlBuilder.
            // They live beside `options`, never inside it: `options` goes to the
            // FormType untouched, and NumberType already has a `scale` that rounds.
            'display'         => $this->display($type, $control['display'] ?? null),
            'maxLength'       => $this->setting($type, $control, 'maxLength', self::TEXT_CONTROLS, 'is_int'),
            'min'             => $this->setting($type, $control, 'min', self::NUMBER_CONTROLS, static fn(mixed $v): bool => \is_int($v) || \is_float($v)),
            'max'             => $this->setting($type, $control, 'max', self::NUMBER_CONTROLS, static fn(mixed $v): bool => \is_int($v) || \is_float($v)),
            'scale'           => $this->setting($type, $control, 'scale', ['number'], static fn(mixed $v): bool => \is_int($v) && $v >= 0),
            // The password's own settings: asked twice, the strength meter and the
            // show/hide eye (the gridview-password-* controllers), the shortest accepted.
            'confirm'         => $this->setting($type, $control, 'confirm', ['password'], 'is_bool') ?? false,
            'strengthMeter'   => $this->setting($type, $control, 'strengthMeter', ['password'], 'is_bool') ?? false,
            'revealToggle'    => $this->setting($type, $control, 'revealToggle', ['password'], 'is_bool') ?? false,
            'minLength'       => $this->setting($type, $control, 'minLength', ['password'], static fn(mixed $v): bool => \is_int($v) && $v >= 0),
            // A remote relation: the URL searching its records, the host's callable
            // labelling the ids, and the field a cascade depends on, with the query
            // parameter its value goes in and its current value.
            'url'             => $url,
            'labels'          => $labels,
            'dependsOn'       => $dependsOn,
            'dependsParam'    => $dependsParam,
            'dependsValue'    => $this->setting($type, $control, 'dependsValue', ['relation'], static fn(mixed $v): bool => \is_string($v) || \is_int($v)),
            // false: the form adds no NotBlank of its own, `required` stays an HTML
            // hint. For a host that validates the submitted data elsewhere and maps
            // its errors back onto the form.
            'validate'        => (bool) ($control['validate'] ?? true),
        ];
    }

    private function display(string $type, mixed $display): ?string
    {
        if ($display === null) {
            return null;
        }
        $enum = self::DISPLAYS[$type] ?? null;
        if ($enum === null) {
            throw new \InvalidArgumentException(sprintf('A "%s" control takes no display; only %s do.', $type, implode(' and ', array_keys(self::DISPLAYS))));
        }
        if (!\is_string($display) || $enum::tryFrom($display) === null) {
            throw new \InvalidArgumentException(sprintf(
                'The display of a "%s" control must be one of: %s.',
                $type,
                implode(', ', array_map(static fn(\BackedEnum $case): string => (string) $case->value, $enum::cases()))
            ));
        }

        return $display;
    }

    /**
     * A bundle setting of the control, or null when not given.
     *
     * @param array<string, mixed>  $control
     * @param list<string>          $types   the controls that accept the setting
     * @param callable(mixed): bool $valid
     */
    private function setting(string $type, array $control, string $key, array $types, callable $valid): mixed
    {
        $value = $control[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\in_array($type, $types, true)) {
            throw new \InvalidArgumentException(sprintf('A "%s" control takes no "%s"; only %s do.', $type, $key, implode(', ', $types)));
        }
        if (!$valid($value)) {
            throw new \InvalidArgumentException(sprintf('The "%s" of a "%s" control has an invalid value: %s.', $key, $type, get_debug_type($value)));
        }

        return $value;
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
