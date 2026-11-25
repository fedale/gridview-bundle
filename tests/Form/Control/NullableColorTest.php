<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Column\Config\ColorColumn;
use Fedale\GridviewBundle\Form\Control\ControlBuilder;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Form\Control\NullableColorType;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * A colour that may be none: the browser's colour input always holds one, so "No color"
 * stands beside it.
 */
class NullableColorTest extends TestCase
{
    private ControlBuilder $controls;

    protected function setUp(): void
    {
        $this->controls = new ControlBuilder(new ControlTypeRegistry());
    }

    /**
     * @param array<string, mixed> $control
     *
     * @return FormInterface<mixed>
     */
    private function form(array $control, mixed $data = null): FormInterface
    {
        $builder = Forms::createFormFactory()->createNamedBuilder('label', FormType::class, ['color' => $data]);
        $this->controls->add($builder, 'color', ['type' => 'color'] + $control, 'Color');

        return $builder->getForm();
    }

    public function testWithoutNullableItIsTheBrowsersColorInput(): void
    {
        [$type] = $this->controls->field(['type' => 'color']);

        $this->assertSame(ColorType::class, $type);
    }

    public function testNoColorIsChecked(): void
    {
        $view = $this->form(['nullable' => true])->createView()['color'];

        $this->assertTrue($view['none']->vars['checked']);
        $this->assertSame('#000000', $view['color']->vars['value']);
        $this->assertSame('gridview-color', $view->vars['attr']['data-controller']);
    }

    public function testAColorIsShownInLowerCase(): void
    {
        $view = $this->form(['nullable' => true], '#1F6FEB')->createView()['color'];

        $this->assertFalse($view['none']->vars['checked']);
        $this->assertSame('#1f6feb', $view['color']->vars['value']);
    }

    public function testNoColorWinsOverTheColorInput(): void
    {
        $form = $this->form(['nullable' => true], '#1f6feb');
        $form->submit(['color' => ['color' => '#000000', 'none' => '1']]);
        $this->assertNull($form->get('color')->getData());

        $form = $this->form(['nullable' => true]);
        $form->submit(['color' => ['color' => '#1F6FEB']]);
        $this->assertSame('#1f6feb', $form->get('color')->getData());
    }

    public function testNullableIsOnlyAColorSetting(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ControlResolver())->resolve(['type' => 'text', 'nullable' => true], 'text');
    }

    public function testTheColorColumnEditsANullableColor(): void
    {
        $this->assertSame(['nullable' => true], ColorColumn::new('label')->nullable()->toArray()['control']);
        [$type] = $this->controls->field(['type' => 'color'] + ColorColumn::new('label')->nullable()->toArray()['control']);
        $this->assertSame(NullableColorType::class, $type);
    }

    public function testTheThemeDrawsTheCheckboxBesideTheColor(): void
    {
        $bridge = \dirname((string) (new \ReflectionClass(FormExtension::class))->getFileName(), 2) . '/Resources/views/Form';
        $twig = new Environment(new FilesystemLoader([\dirname(__DIR__, 3) . '/templates/form', $bridge]));
        $engine = new TwigRendererEngine(['gv_form_theme.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([FormRenderer::class => static fn() => new FormRenderer($engine)]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));

        $html = $twig->createTemplate('{{ form_widget(form.color) }}')->render(['form' => $this->form(['nullable' => true])->createView()]);

        $this->assertStringContainsString('type="color" id="label_color_color" name="label[color][color]"', $html);
        $this->assertStringContainsString('name="label[color][none]"', $html);
        $this->assertStringContainsString('color.none', $html);
    }
}
