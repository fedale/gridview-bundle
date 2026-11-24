<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Form\Control\BooleanDisplay;
use Fedale\GridviewBundle\Form\Control\ChoiceDisplay;
use Fedale\GridviewBundle\Form\Control\ControlBuilder;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Form\Control\ControlType;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

/**
 * A control built from its spec, without any grid column: the path a form with no
 * grid takes, and the one a column's buildControl() delegates to.
 */
class ControlBuilderTest extends TestCase
{
    private ControlBuilder $controls;

    protected function setUp(): void
    {
        $this->controls = new ControlBuilder(new ControlTypeRegistry());
    }

    /**
     * @param array<string, mixed> $control
     *
     * @return array{0: class-string, 1: array<string, mixed>}
     */
    private function field(array $control): array
    {
        return $this->controls->formField((new ControlResolver())->resolve($control, 'text'));
    }

    private function builder(): FormBuilderInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()->createNamedBuilder('record', FormType::class, []);
    }

    public function testAddsTheFieldOfASpecToAFormWithoutAColumn(): void
    {
        $builder = $this->builder();

        $this->controls->add($builder, 'notes', ['type' => ControlType::Textarea, 'required' => true], 'Notes');

        $field = $builder->get('notes');
        $this->assertInstanceOf(TextareaType::class, $field->getType()->getInnerType());
        $this->assertSame('Notes', $field->getOption('label'));
        $this->assertTrue($field->getOption('required'));
    }

    public function testATextareaIsItsOwnControl(): void
    {
        $this->assertSame(TextareaType::class, $this->field(['type' => 'textarea'])[0]);
    }

    public function testARequiredControlIsValidatedByTheForm(): void
    {
        [, $options] = $this->field(['type' => 'textarea', 'required' => true]);

        $this->assertTrue($options['required']);
        $this->assertSame('', $options['empty_data']);
        $this->assertInstanceOf(NotBlank::class, $options['constraints'][0]);
    }

    public function testWithoutValidationRequiredIsOnlyTheHtmlHint(): void
    {
        $explicit = new NotBlank();
        [, $options] = $this->field(['type' => 'text', 'required' => true, 'validate' => false, 'constraints' => [$explicit]]);

        $this->assertTrue($options['required']);
        $this->assertArrayNotHasKey('empty_data', $options);
        // The constraints the spec lists stay: only the implied NotBlank goes.
        $this->assertSame([$explicit], $options['constraints']);
    }

    public function testMaxLengthBecomesTheMaxlengthAttribute(): void
    {
        [, $options] = $this->field(['type' => 'text', 'maxLength' => 40]);

        $this->assertSame(['maxlength' => 40], $options['attr']);
    }

    public function testAnAttributeTheSpecSetsWinsOverTheSetting(): void
    {
        [, $options] = $this->field(['type' => 'text', 'maxLength' => 40, 'options' => ['attr' => ['maxlength' => 10, 'placeholder' => 'Title']]]);

        $this->assertSame(['maxlength' => 10, 'placeholder' => 'Title'], $options['attr']);
    }

    public function testBoundsAndScaleBecomeTheAttributesOfTheNumberInput(): void
    {
        [$type, $options] = $this->field(['type' => 'number', 'min' => 0, 'max' => 99.5, 'scale' => 2]);

        $this->assertSame(NumberType::class, $type);
        $this->assertSame(['min' => 0, 'max' => 99.5, 'step' => '0.01'], $options['attr']);
        // NumberType's own scale would round extra decimals away: it stays unset.
        $this->assertArrayNotHasKey('scale', $options);
    }

    public function testAScaleOfZeroStepsByOne(): void
    {
        $this->assertSame(['step' => '1'], $this->field(['type' => 'number', 'scale' => 0])[1]['attr']);
    }

    public function testASwitchKeepsTheClassesTheSpecGives(): void
    {
        [$type, $options] = $this->field(['type' => 'boolean', 'display' => BooleanDisplay::Switch, 'options' => ['attr' => ['class' => 'wide']]]);

        $this->assertSame(CheckboxType::class, $type);
        $this->assertSame(['class' => 'wide gv-switch', 'role' => 'switch'], $options['attr']);
    }

    public function testACheckboxIsTheDefaultDisplay(): void
    {
        $this->assertArrayNotHasKey('attr', $this->field(['type' => 'boolean', 'display' => 'checkbox'])[1]);
    }

    /**
     * @dataProvider choiceDisplays
     *
     * @param array<string, mixed> $expected
     */
    public function testAChoiceDisplayExpandsTheOptions(string $display, array $expected): void
    {
        [$type, $options] = $this->field(['type' => 'choice', 'display' => $display, 'options' => ['choices' => ['Small' => 's']]]);

        $this->assertSame(ChoiceType::class, $type);
        unset($options['choices'], $options['required']);
        $this->assertSame($expected, $options);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function choiceDisplays(): iterable
    {
        yield 'dropdown' => ['dropdown', []];
        yield 'radio' => ['radio', ['expanded' => true, 'placeholder' => false]];
        yield 'segmented' => ['segmented', ['expanded' => true, 'placeholder' => false, 'attr' => ['class' => 'gv-segmented']]];
        yield 'checkboxes' => ['checkboxes', ['expanded' => true, 'multiple' => true]];
    }

    public function testAnOptionTheSpecSetsWinsOverTheDisplay(): void
    {
        [, $options] = $this->field(['type' => 'choice', 'display' => ChoiceDisplay::Radio, 'options' => ['placeholder' => 'None']]);

        $this->assertSame('None', $options['placeholder']);
    }

    public function testAControlWithoutTypeInheritsTheDataType(): void
    {
        $builder = $this->builder();

        $this->controls->add($builder, 'title', true);

        $this->assertInstanceOf(TextType::class, $builder->get('title')->getType()->getInnerType());
    }

    public function testAMediaControlIsNotMapped(): void
    {
        $this->assertFalse($this->field(['type' => 'media'])[1]['mapped']);
    }
}
