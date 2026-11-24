<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Column\Config\ListColumn;
use Fedale\GridviewBundle\Form\Control\ControlBuilder;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Form\Control\ControlRowType;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Form\Control\RowCollectionType;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * Repeatable rows: a collection of controls, added and removed in the page.
 */
class CollectionControlTest extends TestCase
{
    private const PHONES = [
        'type'  => 'collection',
        'entry' => ['number' => ['type' => 'tel', 'label' => 'Number'], 'note' => ['type' => 'text', 'maxLength' => 40]],
    ];

    private ControlBuilder $controls;

    protected function setUp(): void
    {
        $this->controls = new ControlBuilder(new ControlTypeRegistry());
    }

    /**
     * @param array<string, mixed>       $control
     * @param list<array<string, mixed>> $rows
     *
     * @return FormInterface<mixed>
     */
    private function form(array $control, array $rows = []): FormInterface
    {
        $builder = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->createNamedBuilder('contact', FormType::class, ['phones' => $rows]);
        $this->controls->add($builder, 'phones', $control, 'Phones');

        return $builder->getForm();
    }

    public function testItsRowsAreBuiltFromTheControlsOfTheEntry(): void
    {
        [$type, $options] = $this->controls->field(self::PHONES + ['min' => 1, 'max' => 3, 'inline' => true]);

        $this->assertSame(RowCollectionType::class, $type);
        $this->assertSame(ControlRowType::class, $options['entry_type']);
        $this->assertSame(1, $options['min']);
        $this->assertSame(3, $options['max']);
        $this->assertTrue($options['inline']);
        // The rows are counted on the server, not compared as numbers.
        $this->assertEquals([new Count(min: 1, max: 3)], $options['constraints']);
        $this->assertArrayNotHasKey('attr', $options);
    }

    public function testARowHasTheFieldsOfItsEntry(): void
    {
        $row = $this->form(self::PHONES, [['number' => '+39 011 555 0101', 'note' => 'Office']])->get('phones')->get('0');

        $this->assertSame(['number', 'note'], array_keys($row->all()));
        $this->assertSame('Number', $row->get('number')->getConfig()->getOption('label'));
        $this->assertSame(['maxlength' => 40], $row->get('note')->getConfig()->getOption('attr'));
    }

    public function testRowsAreAddedRemovedAndBlankOnesDropped(): void
    {
        $form = $this->form(self::PHONES, [['number' => '+39 011 555 0101', 'note' => 'Office'], ['number' => '+39 011 555 0102', 'note' => null]]);

        $form->submit(['phones' => [
            1   => ['number' => '+39 011 555 0102', 'note' => 'Home'],
            7   => ['number' => '+39 333 555 0199', 'note' => ''],
            8   => ['number' => '', 'note' => ''],
        ]]);

        $this->assertSame([
            1 => ['number' => '+39 011 555 0102', 'note' => 'Home'],
            7 => ['number' => '+39 333 555 0199', 'note' => null],
        ], $form->get('phones')->getData());
    }

    public function testTooManyRowsAreRefusedByTheServer(): void
    {
        $form = $this->form(self::PHONES + ['max' => 1]);

        $form->submit(['phones' => [['number' => '1'], ['number' => '2']]]);

        $this->assertFalse($form->isValid());
    }

    public function testAHostTypeMayBeTheEntry(): void
    {
        [, $options] = $this->controls->field(['type' => 'collection', 'options' => ['entry_type' => TextType::class]]);

        $this->assertSame(TextType::class, $options['entry_type']);
    }

    public function testACollectionNeedsItsEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A "collection" control requires an entry');

        (new ControlResolver())->resolve(['type' => 'collection'], 'text');
    }

    public function testItsBoundsAreRowCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "min" of a "collection" control has an invalid value: float.');

        (new ControlResolver())->resolve(self::PHONES + ['min' => 1.5], 'text');
    }

    public function testTheListColumnEditsItsRows(): void
    {
        $this->assertSame(
            ['type' => 'collection', 'entry' => ['number' => ['type' => 'tel']], 'min' => 1, 'max' => 0, 'inline' => true],
            ListColumn::new('phones')->rows(['number' => ['type' => 'tel']], min: 1, inline: true)->toArray()['control'],
        );
    }

    public function testTheThemeDrawsTheRowsForTheirController(): void
    {
        $html = $this->render($this->form(self::PHONES + ['min' => 1, 'inline' => true, 'options' => ['add_label' => 'Add a phone']], [
            3 => ['number' => '+39 011 555 0101', 'note' => 'Office'],
        ]));

        $this->assertStringContainsString('<div id="contact_phones" class="gv-collection" data-controller="gridview-collection"', $html);
        $this->assertStringContainsString('data-gridview-collection-prototype-name-value="__name__"', $html);
        // The next row takes the key after the highest one.
        $this->assertStringContainsString('data-gridview-collection-index-value="4"', $html);
        $this->assertStringContainsString('data-gridview-collection-min-value="1"', $html);
        $this->assertStringContainsString('<fieldset class="gv-collection__row gv-collection__row--inline" data-gridview-collection-target="row"><div class="gv-collection__fields"><div id="contact_phones_3">', $html);
        $this->assertStringContainsString('aria-label="collection.remove">×</button>', $html);
        $this->assertStringContainsString('+ Add a phone', $html);
        // The prototype is a whole row, fieldset included.
        $this->assertStringContainsString('data-gridview-collection-prototype-value="&lt;fieldset&#x20;class&#x3D;&quot;gv-collection__row&#x20;gv-collection__row--inline&quot;', $html);
        $this->assertStringContainsString('contact&#x5B;phones&#x5D;&#x5B;__name__&#x5D;&#x5B;number&#x5D;', $html);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function render(FormInterface $form): string
    {
        $bridge = \dirname((string) (new \ReflectionClass(FormExtension::class))->getFileName(), 2) . '/Resources/views/Form';
        $twig = new Environment(new FilesystemLoader([\dirname(__DIR__, 3) . '/templates/form', $bridge]));
        $engine = new TwigRendererEngine(['gv_form_theme.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([FormRenderer::class => static fn() => new FormRenderer($engine)]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));

        return $twig->createTemplate('{{ form_widget(form.phones) }}')->render(['form' => $form->createView()]);
    }
}
