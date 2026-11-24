<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

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
        $upload = $control['upload'] ?? null;
        if ($control['type'] === 'media' && \is_callable($upload)) {
            $form->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($name, $upload): void {
                $form = $event->getForm();
                if (!$form->has($name)) {
                    return;
                }
                $file = $form->get($name)->getData();
                if ($file === null) {
                    return;
                }
                $upload($file, $event->getData(), $form);
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
     * @return array{0: class-string, 1: array<string, mixed>}
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
     * @return array{0: class-string, 1: array<string, mixed>}
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
        if ($validate && \is_int($control['maxLength'] ?? null)) {
            $constraints[] = new Length(max: $control['maxLength']);
        }
        if ($validate && (($control['min'] ?? null) !== null || ($control['max'] ?? null) !== null)) {
            $constraints[] = new Range(min: $control['min'] ?? null, max: $control['max'] ?? null);
        }
        foreach ($control['constraints'] ?? [] as $constraint) {
            $constraints[] = $constraint;
        }
        if ($constraints !== []) {
            $options['constraints'] = $constraints;
        }

        $options = $this->settings($type, $control, $options);

        if ($type === 'media') {
            $options['mapped'] = false;
        }

        return [$this->registry->get($type), $options];
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

        if (\is_int($control['maxLength'] ?? null)) {
            $attr += ['maxlength' => $control['maxLength']];
        }
        foreach (['min', 'max'] as $bound) {
            if (($control[$bound] ?? null) !== null) {
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
        $classes = \is_string($attr['class'] ?? null) ? $attr['class'] : '';
        $attr['class'] = trim($classes . ' ' . $class);

        return $attr;
    }
}
