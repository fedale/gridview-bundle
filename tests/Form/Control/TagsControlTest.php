<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Column\Config\ListColumn;
use Fedale\GridviewBundle\Form\Control\ControlBuilder;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Form\Control\TagsType;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * Free text labels: a comma-separated input the gridview-tags controller draws as chips, bound
 * as a list of strings.
 */
class TagsControlTest extends TestCase
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
        $builder = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->createNamedBuilder('ticket', FormType::class, ['tags' => $data]);
        $this->controls->add($builder, 'tags', ['type' => 'tags'] + $control, 'Tags');

        return $builder->getForm();
    }

    public function testTheLabelsAreTheTextOfTheInput(): void
    {
        $view = $this->form([], ['urgent', 'client-x'])->createView()['tags'];

        $this->assertSame('urgent, client-x', $view->vars['value']);
        $this->assertSame('gridview-tags', $view->vars['attr']['data-controller']);
    }

    public function testTheTextBecomesTheLabelsTrimmedAndOnceEach(): void
    {
        $form = $this->form([]);

        $form->submit(['tags' => ' urgent ,  client   x, , URGENT, Later ']);

        $this->assertSame(['urgent', 'client x', 'Later'], $form->get('tags')->getData());
    }

    public function testAnEmptyInputIsNoLabel(): void
    {
        $form = $this->form([], ['urgent']);

        $form->submit(['tags' => '']);

        $this->assertSame([], $form->get('tags')->getData());
    }

    public function testALabelTakesTheSpellingOfItsSuggestion(): void
    {
        $this->assertSame(['Client X', 'new'], TagsType::parse('client x, new', ['Client X']));
    }

    public function testItsSettingsReachTheController(): void
    {
        $view = $this->form(['suggestions' => ['urgent', 'later'], 'restrict' => true, 'max' => 3, 'maxLength' => 20, 'url' => '/tags'])->createView()['tags'];
        $attr = $view->vars['attr'];

        $this->assertSame('["urgent","later"]', $attr['data-gridview-tags-suggestions-value']);
        $this->assertSame('true', $attr['data-gridview-tags-restrict-value']);
        $this->assertSame(3, $attr['data-gridview-tags-max-value']);
        $this->assertSame(20, $attr['data-gridview-tags-max-length-value']);
        $this->assertSame('/tags', $attr['data-gridview-tags-url-value']);
        // maxLength measures a label, not the whole input.
        $this->assertArrayNotHasKey('maxlength', $attr);
    }

    public function testTheServerChecksEachLabelTheirCountAndTheSuggestions(): void
    {
        [$type, $options] = $this->controls->field(['type' => 'tags', 'suggestions' => ['urgent'], 'restrict' => true, 'max' => 3, 'maxLength' => 20]);

        $this->assertSame(TagsType::class, $type);
        $this->assertEquals([
            new All([new Length(max: 20)]),
            new Count(max: 3),
            new Choice(choices: ['urgent'], multiple: true),
        ], $options['constraints']);
    }

    public function testALabelOutsideTheSuggestionsIsRefusedWithRestrict(): void
    {
        $form = $this->form(['suggestions' => ['Urgent', 'Later'], 'restrict' => true]);

        $form->submit(['tags' => 'urgent, someday']);

        $this->assertFalse($form->isValid());
        $this->assertSame(['Urgent', 'someday'], $form->get('tags')->getData());
    }

    public function testRestrictNeedsSuggestions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A "tags" control with restrict requires its suggestions');

        (new ControlResolver())->resolve(['type' => 'tags', 'restrict' => true], 'text');
    }

    public function testTheListColumnEditsItsTags(): void
    {
        $this->assertSame(
            ['type' => 'tags', 'suggestions' => ['urgent'], 'restrict' => false, 'max' => 5],
            ListColumn::new('tags')->tags(['urgent'], max: 5)->toArray()['control'],
        );
    }

    public function testTheThemeGivesTheControllerItsTexts(): void
    {
        $bridge = \dirname((string) (new \ReflectionClass(FormExtension::class))->getFileName(), 2) . '/Resources/views/Form';
        $twig = new Environment(new FilesystemLoader([\dirname(__DIR__, 3) . '/templates/form', $bridge]));
        $engine = new TwigRendererEngine(['gv_form_theme.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([FormRenderer::class => static fn() => new FormRenderer($engine)]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));

        $html = $twig->createTemplate('{{ form_widget(form.tags) }}')->render(['form' => $this->form([], ['urgent'])->createView()]);

        $this->assertStringContainsString('type="text" id="ticket_tags" name="ticket[tags]"', $html);
        $this->assertStringContainsString('data-gridview-tags-remove-label-value="tags.remove"', $html);
        $this->assertStringContainsString('data-gridview-tags-placeholder-value="tags.add"', $html);
        $this->assertStringContainsString('class="gv-form-control" value="urgent"', $html);
    }
}
