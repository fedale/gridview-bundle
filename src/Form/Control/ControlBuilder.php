<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\UX\Autocomplete\Form\AutocompleteChoiceTypeExtension;

/**
 * Adds a control (a write-side field) to any Symfony form, from the same `control`
 * spec a column takes. A grid's CRUD form goes through it (see
 * {@see \Fedale\GridviewBundle\Column\AbstractColumn::buildControl()}), and so can a
 * form that has no grid at all: one implementation of each control, wherever it is
 * drawn.
 *
 *     $controls->add($builder, 'price', ['type' => 'number', 'scale' => 2, 'min' => 0]);
 */
class ControlBuilder
{
    /** Controls whose input is a number: `min` and `max` are its attributes and a Range. */
    private const NUMBER_CONTROLS = ['number', 'integer'];

    public function __construct(
        private readonly ControlTypeRegistry $registry,
        private readonly ControlResolver $resolver = new ControlResolver(),
    ) {
    }

    /**
     * Resolves a control spec and adds its field to the form.
     *
     * @param FormBuilderInterface<mixed>                  $form
     * @param bool|string|array<string, mixed>|ControlType $control
     * @param string                                       $dataType the data type a control without `type` inherits
     */
    public function add(FormBuilderInterface $form, string $name, bool|string|array|ControlType $control, ?string $label = null, string $dataType = 'text'): void
    {
        $this->addResolved($form, $name, $this->resolver->resolve($control, $dataType), $label);
    }

    /**
     * Adds the field of a control already resolved by {@see ControlResolver}, as a
     * column holds it.
     *
     * @param FormBuilderInterface<mixed> $form
     * @param array<string, mixed>        $control
     */
    public function addResolved(FormBuilderInterface $form, string $name, array $control, ?string $label = null): void
    {
        [$formType, $options] = $this->formField($control, $label);
        $form->add($name, $formType, $options);

        // `media` controls (FileType) are virtual: the attribute is not a property
        // of the entity. The bundle owns phase 1 (receiving and validating the
        // upload); the host app's `upload` callable owns phase 2 (storing the bytes
        // and populating the real entity fields), invoked once the form is bound.
        // A media control showing its files hands over each new upload the same way;
        // the ids it keeps are no upload.
        $upload = $control['upload'] ?? null;
        if ($control['type'] === 'media' && \is_callable($upload)) {
            $form->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($name, $upload): void {
                $form = $event->getForm();
                if (!$form->has($name)) {
                    return;
                }
                $data = $form->get($name)->getData();
                foreach (\is_array($data) ? $data : [$data] as $file) {
                    if ($file instanceof UploadedFile) {
                        $upload($file, $event->getData(), $form);
                    }
                }
            });
        }
    }

    /**
     * The FormType and the options of a control spec, without adding it: for a host
     * that places the field itself (inside a collection entry, under another name,
     * with options of its own on top).
     *
     * @param bool|string|array<string, mixed>|ControlType $control
     *
     * @return array{0: class-string<FormTypeInterface>, 1: array<string, mixed>}
     */
    public function field(bool|string|array|ControlType $control, ?string $label = null, string $dataType = 'text'): array
    {
        return $this->formField($this->resolver->resolve($control, $dataType), $label);
    }

    /**
     * The FormType and the options of a control already resolved by
     * {@see ControlResolver}, as a column holds it.
     *
     * @param array<string, mixed> $control
     *
     * @return array{0: class-string<FormTypeInterface>, 1: array<string, mixed>}
     */
    public function formField(array $control, ?string $label = null): array
    {
        $type    = (string) $control['type'];
        $options = $control['options'] ?? [];
        $options['required'] ??= $control['required'] ?? false;
        if ($label !== null && !\array_key_exists('label', $options)) {
            $options['label'] = $label;
        }
        $validate = $control['validate'] ?? true;

        // An empty required text/textarea field submits as null and would break a
        // non-nullable typed setter (e.g. setCode(string)) during binding, before
        // validation runs. Coerce to '' so NotBlank can report it gracefully.
        if ($validate
            && ($control['required'] ?? false) === true
            && \in_array($type, ['text', 'textarea', 'html'], true)
            && !\array_key_exists('empty_data', $options)
        ) {
            $options['empty_data'] = '';
        }

        // Server-side validation: NotBlank for required fields (the `required`
        // option alone is only an HTML hint), Length and Range for the settings below,
        // plus any explicit constraints. Without `validate`, the host validates
        // elsewhere: only its explicit ones stay.
        $constraints = $options['constraints'] ?? [];
        if ($validate && ($control['required'] ?? false) === true) {
            $message = $control['requiredMessage'] ?? null;
            $constraints[] = $message !== null ? new NotBlank(message: $message) : new NotBlank();
        }
        // The length and the bounds are attributes for the browser (see settings())
        // and constraints for the server: the attributes alone are a hint anyone can
        // bypass.
        $minLength = \is_int($control['minLength'] ?? null) ? $control['minLength'] : null;
        $maxLength = \is_int($control['maxLength'] ?? null) ? $control['maxLength'] : null;
        if ($validate && $type !== 'tags' && ($minLength !== null || $maxLength !== null)) {
            $constraints[] = new Length(min: $minLength, max: $maxLength);
        }
        if ($validate && !\in_array($type, ['collection', 'tags'], true) && (($control['min'] ?? null) !== null || ($control['max'] ?? null) !== null)) {
            $constraints[] = new Range(min: $control['min'] ?? null, max: $control['max'] ?? null);
        }
        // A collection's bounds count its rows, 0 meaning no limit.
        if ($validate && $type === 'collection' && (($control['min'] ?? 0) > 0 || ($control['max'] ?? 0) > 0)) {
            $constraints[] = new Count(min: ($control['min'] ?? 0) > 0 ? $control['min'] : null, max: ($control['max'] ?? 0) > 0 ? $control['max'] : null);
        }
        // A tags control checks each label, their count, and with `restrict` that each one
        // is a suggestion (TagsType has already given it the suggestion's spelling).
        if ($validate && $type === 'tags') {
            if ($maxLength !== null) {
                $constraints[] = new All([new Length(max: $maxLength)]);
            }
            if (\is_int($control['max'] ?? null) && $control['max'] > 0) {
                $constraints[] = new Count(max: $control['max']);
            }
            if (($control['restrict'] ?? false) === true) {
                $constraints[] = new Choice(choices: $control['suggestions'] ?? [], multiple: true);
            }
        }
        foreach ($control['constraints'] ?? [] as $constraint) {
            $constraints[] = $constraint;
        }
        if ($constraints !== []) {
            $options['constraints'] = $constraints;
        }

        $options = $this->settings($type, $control, $options);

        if ($type === 'media' && ($control['showsFiles'] ?? false) === true) {
            // The files and their ids are the data: mapped, unless the spec says otherwise.
            $multiple = ($control['multiple'] ?? false) === true;
            $options += ['current' => $control['current'] ?? ($multiple ? [] : null)];
            if (\is_string($control['accept'] ?? null)) {
                $options += ['accept' => $control['accept']];
            }

            return [$multiple ? MediaListType::class : MediaType::class, $options];
        }
        if ($type === 'media') {
            $options['mapped'] = false;
            if (\is_string($control['accept'] ?? null)) {
                $options['attr'] = (\is_array($options['attr'] ?? null) ? $options['attr'] : []) + ['accept' => $control['accept']];
            }
        }
        if ($type === 'password') {
            return $this->password($control, $options);
        }
        if ($type === 'collection') {
            return [$this->registry->get('collection'), $this->collection($control, $options)];
        }
        if ($type === 'tags') {
            return [$this->registry->get('tags'), $options + array_filter([
                'suggestions'     => $control['suggestions'] ?? [],
                'restrict'        => ($control['restrict'] ?? false) === true,
                'max'             => \is_int($control['max'] ?? null) && $control['max'] > 0 ? $control['max'] : null,
                'max_length'      => \is_int($control['maxLength'] ?? null) ? $control['maxLength'] : null,
                'suggestions_url' => \is_string($control['url'] ?? null) ? $control['url'] : null,
            ], static fn(mixed $value): bool => $value !== null)];
        }
        if ($type === 'relation' && \is_string($control['url'] ?? null)) {
            return $this->remoteRelation($control, $options);
        }

        return [$this->registry->get($type), $options];
    }

    /**
     * The options of a `collection` control: its rows built from the `entry` control
     * specs (unless the spec names its own entry_type), and the bounds and layout the
     * gridview-collection controller and the form theme read.
     *
     * @param array<string, mixed> $control
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function collection(array $control, array $options): array
    {
        if (\is_array($control['entry'] ?? null)) {
            $options += [
                'entry_type'    => ControlRowType::class,
                'entry_options' => ['controls' => $control['entry'], 'control_builder' => $this],
            ];
        }

        return $options + [
            'min'    => \is_int($control['min'] ?? null) ? $control['min'] : 0,
            'max'    => \is_int($control['max'] ?? null) ? $control['max'] : 0,
            'inline' => ($control['inline'] ?? false) === true,
        ];
    }

    /**
     * A relation to records the form doesn't hold: scalar ids in and out, no Doctrine.
     * The browser searches the records at the control's URL (Symfony UX Autocomplete);
     * the form knows the current ids and the submitted ones only, labelled by the host's
     * `labels` callable (see {@see RemoteChoiceLoader}).
     *
     * A cascade (`dependsOn`) puts the gridview-dependent-select controller on the row:
     * when the field it depends on changes, the choice is emptied and the search URL
     * takes the new value in `dependsParam`. That the chosen record belongs to the
     * deciding one is for the host to check on the server.
     *
     * @param array<string, mixed> $control
     * @param array<string, mixed> $options
     *
     * @return array{0: class-string<FormTypeInterface>, 1: array<string, mixed>}
     */
    private function remoteRelation(array $control, array $options): array
    {
        if (!class_exists(AutocompleteChoiceTypeExtension::class)) {
            throw new \LogicException('A remote "relation" control needs Symfony UX Autocomplete. Try running "composer require symfony/ux-autocomplete".');
        }
        /** @var callable(list<string>): array<array-key, string> $labels checked by ControlResolver */
        $labels = $control['labels'];
        $loader = new RemoteChoiceLoader($labels(...));
        $url = (string) $control['url'];

        $dependsOn = \is_string($control['dependsOn'] ?? null) ? $control['dependsOn'] : null;
        if ($dependsOn !== null) {
            $param = (string) $control['dependsParam'];
            $rowAttr = (\is_array($options['row_attr'] ?? null) ? $options['row_attr'] : []) + [
                'data-gridview-dependent-select-field-value' => $dependsOn,
                'data-gridview-dependent-select-url-value'   => $url,
                'data-gridview-dependent-select-param-value' => $param,
            ];
            $options['row_attr'] = self::withToken($rowAttr, 'data-controller', 'gridview-dependent-select');
            // Filtered by the current value from the start; an empty one lists nothing
            // until the deciding field is chosen.
            $value = $control['dependsValue'] ?? null;
            $url .= (str_contains($url, '?') ? '&' : '?') . rawurlencode($param) . '=' . rawurlencode(\is_scalar($value) ? (string) $value : '');
        }

        return [ChoiceType::class, $options + [
            'choice_loader'    => $loader,
            'choice_label'     => static fn(string $id): string => $loader->label($id),
            'autocomplete'     => true,
            'autocomplete_url' => $url,
            // A cascade lists the few records of the chosen parent as soon as it opens.
            'min_characters'   => $dependsOn !== null ? 0 : 1,
        ]];
    }

    /**
     * A password input, never prefilled by the browser's saved one, with the strength
     * meter and the show/hide eye when asked; asked twice (`confirm`), a RepeatedType
     * whose two inputs must match. The meter and the eye go on the input typed first,
     * never on its confirmation, and so do the label and the help.
     *
     * @param array<string, mixed> $control
     * @param array<string, mixed> $options
     *
     * @return array{0: class-string<FormTypeInterface>, 1: array<string, mixed>}
     */
    private function password(array $control, array $options): array
    {
        $attr = (\is_array($options['attr'] ?? null) ? $options['attr'] : []) + ['autocomplete' => 'new-password'];
        $first = $attr;
        $controllers = [];
        if (($control['strengthMeter'] ?? false) === true) {
            $controllers[] = 'gridview-password-strength';
            $first = self::withToken($first, 'data-action', 'gridview-password-strength#update');
            $first += ['data-gridview-password-strength-min-length-value' => $control['minLength'] ?? 8];
        }
        if (($control['revealToggle'] ?? false) === true) {
            $controllers[] = 'gridview-password-reveal';
        }
        foreach ($controllers as $controller) {
            $first = self::withToken($first, 'data-controller', $controller);
        }

        if (($control['confirm'] ?? false) !== true) {
            $options['attr'] = $first;

            return [$this->registry->get('password'), $options];
        }

        // The group draws no label or help of its own: they move to the first input.
        // What the spec sets in first_options/second_options wins, key by key.
        $firstOptions = ['attr' => $first];
        foreach (['label', 'label_translation_parameters', 'help', 'help_translation_parameters'] as $key) {
            if (\array_key_exists($key, $options)) {
                $firstOptions[$key] = $options[$key];
                unset($options[$key]);
            }
        }
        // The confirmation's label does not repeat the first one: that may be a
        // translation key of the host's domain, which this one could not translate.
        // The default label comes with its domain, so a label the spec sets is
        // translated in the form's domain, as the first one is.
        $secondOptions = \is_array($options['second_options'] ?? null) ? $options['second_options'] : [];
        if (!\array_key_exists('label', $secondOptions)) {
            $secondOptions += ['label' => 'field.password_repeat', 'translation_domain' => 'GridviewBundle'];
        }
        unset($options['attr']);

        return [RepeatedType::class, [
            'type'           => PasswordType::class,
            'label'          => false,
            'options'        => ['attr' => $attr] + (\is_array($options['options'] ?? null) ? $options['options'] : []),
            'first_options'  => (\is_array($options['first_options'] ?? null) ? $options['first_options'] : []) + $firstOptions,
            'second_options' => $secondOptions,
        ] + $options];
    }

    /**
     * Turns the bundle's control settings (display, maxLength, min, max, scale) into
     * form options. An option the spec sets itself wins over the setting.
     *
     * @param array<string, mixed> $control
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function settings(string $type, array $control, array $options): array
    {
        $attr = \is_array($options['attr'] ?? null) ? $options['attr'] : [];

        // A tags input holds several labels: its maxLength is per label (see tags()).
        if ($type !== 'tags' && \is_int($control['maxLength'] ?? null)) {
            $attr += ['maxlength' => $control['maxLength']];
        }
        foreach (['min', 'max'] as $bound) {
            if (\in_array($type, self::NUMBER_CONTROLS, true) && ($control[$bound] ?? null) !== null) {
                $attr += [$bound => $control[$bound]];
            }
        }
        // The scale is the step of the input, not NumberType's `scale` option: that
        // one would round extra decimals silently instead of letting them be reported.
        if (\is_int($control['scale'] ?? null)) {
            $attr += ['step' => $control['scale'] > 0 ? '0.' . str_repeat('0', $control['scale'] - 1) . '1' : '1'];
        }

        $display = $control['display'] ?? null;
        if ($type === 'boolean' && $display === BooleanDisplay::Switch->value) {
            $attr = self::withClass($attr, 'gv-switch') + ['role' => 'switch'];
        }
        if ($type === 'choice' && $display !== null && $display !== ChoiceDisplay::Dropdown->value) {
            $options += ['expanded' => true];
            if ($display === ChoiceDisplay::Checkboxes->value) {
                $options += ['multiple' => true];
            } else {
                // An empty radio group already means "no value".
                $options += ['placeholder' => false];
            }
            if ($display === ChoiceDisplay::Segmented->value) {
                $attr = self::withClass($attr, 'gv-segmented');
            }
        }

        if ($attr !== []) {
            $options['attr'] = $attr;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $attr
     *
     * @return array<string, mixed>
     */
    private static function withClass(array $attr, string $class): array
    {
        return self::withToken($attr, 'class', $class);
    }

    /**
     * Appends a token to a space-separated attribute (class, data-controller,
     * data-action), keeping the ones the spec already gives.
     *
     * @param array<string, mixed> $attr
     *
     * @return array<string, mixed>
     */
    private static function withToken(array $attr, string $name, string $token): array
    {
        $tokens = \is_string($attr[$name] ?? null) ? $attr[$name] : '';
        $attr[$name] = trim($tokens . ' ' . $token);

        return $attr;
    }
}
