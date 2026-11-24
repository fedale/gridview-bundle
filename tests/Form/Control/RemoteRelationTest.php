<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Form\Control\ControlBuilder;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\UX\Autocomplete\Checksum\ChecksumCalculator;
use Symfony\UX\Autocomplete\Form\AutocompleteChoiceTypeExtension;

/**
 * A relation to records the form doesn't hold (no Doctrine): ids in and out, labelled
 * by the host, searched by the browser at a URL.
 */
class RemoteRelationTest extends TestCase
{
    private const PROVINCES = ['to' => 'Turin', 'mi' => 'Milan', 'rm' => 'Rome'];

    private ControlBuilder $controls;

    /** @var list<list<string>> the ids each call of the labels callable was given */
    private array $lookups = [];

    protected function setUp(): void
    {
        $this->controls = new ControlBuilder(new ControlTypeRegistry());
        $this->lookups = [];
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    public function labels(array $ids): array
    {
        $this->lookups[] = $ids;

        return array_intersect_key(self::PROVINCES, array_flip($ids));
    }

    /**
     * @param array<string, mixed> $control
     *
     * @return FormInterface<mixed>
     */
    private function form(array $control, mixed $data = null): FormInterface
    {
        $builder = Forms::createFormFactoryBuilder()
            ->addTypeExtension(new AutocompleteChoiceTypeExtension(new ChecksumCalculator('test')))
            ->getFormFactory()
            ->createNamedBuilder('record', FormType::class, ['province' => $data]);
        $this->controls->add($builder, 'province', ['type' => 'relation', 'url' => '/provinces', 'labels' => $this->labels(...)] + $control, 'Province');

        return $builder->getForm();
    }

    public function testARemoteRelationIsASelectSearchingAtItsUrl(): void
    {
        [$type, $options] = $this->controls->field(['type' => 'relation', 'url' => '/provinces', 'labels' => $this->labels(...)]);

        $this->assertSame(ChoiceType::class, $type);
        $this->assertTrue($options['autocomplete']);
        $this->assertSame('/provinces', $options['autocomplete_url']);
        $this->assertSame(1, $options['min_characters']);
        $this->assertArrayNotHasKey('row_attr', $options);
    }

    public function testItOffersOnlyTheCurrentRecordUntilTheUserSearches(): void
    {
        $view = $this->form([], 'mi')->createView()['province'];

        $this->assertSame(['mi' => 'Milan'], array_map(static fn($choice) => $choice->label, array_column($view->vars['choices'], null, 'value')));
        $this->assertSame('mi', $view->vars['value']);
        $this->assertSame([['mi']], $this->lookups);
    }

    public function testItAcceptsARecordItHasNotShown(): void
    {
        $form = $this->form([], 'mi');

        $form->submit(['province' => 'rm']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame('rm', $form->get('province')->getData());
        // Each id is looked up once.
        $this->assertSame([['mi'], ['rm']], $this->lookups);
    }

    public function testAnIdNamingNoRecordIsRefused(): void
    {
        $form = $this->form([]);

        $form->submit(['province' => 'xx']);

        $this->assertFalse($form->get('province')->isSynchronized());
    }

    public function testAMultipleRelationKeepsTheOrderOfItsRecords(): void
    {
        $view = $this->form(['options' => ['multiple' => true]], ['to', 'rm', 'mi'])->createView()['province'];

        $this->assertSame(['to', 'rm', 'mi'], array_map(static fn($choice) => $choice->value, $view->vars['choices']));
        $this->assertSame(['to', 'rm', 'mi'], $view->vars['value']);
    }

    public function testACascadeFiltersTheSearchByTheFieldItDependsOn(): void
    {
        [, $options] = $this->controls->field([
            'type' => 'relation', 'url' => '/municipalities?active=1', 'labels' => $this->labels(...),
            'dependsOn' => 'province', 'dependsParam' => 'filter[province]', 'dependsValue' => 'to',
            'options' => ['row_attr' => ['data-controller' => 'highlight']],
        ]);

        $this->assertSame('/municipalities?active=1&filter%5Bprovince%5D=to', $options['autocomplete_url']);
        $this->assertSame(0, $options['min_characters']);
        $this->assertSame([
            'data-controller'                            => 'highlight gridview-dependent-select',
            'data-gridview-dependent-select-field-value' => 'province',
            'data-gridview-dependent-select-url-value'   => '/municipalities?active=1',
            'data-gridview-dependent-select-param-value' => 'filter[province]',
        ], $options['row_attr']);
    }

    public function testACascadeWithNothingChosenListsNothing(): void
    {
        [, $options] = $this->controls->field([
            'type' => 'relation', 'url' => '/municipalities', 'labels' => $this->labels(...),
            'dependsOn' => 'province', 'dependsParam' => 'filter[province]',
        ]);

        $this->assertSame('/municipalities?filter%5Bprovince%5D=', $options['autocomplete_url']);
    }

    /**
     * @dataProvider invalidSpecs
     *
     * @param array<string, mixed> $control
     */
    public function testAnIncompleteRemoteRelationIsRefused(array $control, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new ControlResolver())->resolve($control + ['type' => 'relation'], 'text');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSpecs(): iterable
    {
        yield 'neither an entity nor a url' => [[], 'requires options.class (the related entity FQCN) or a url'];
        yield 'a url without labels' => [['url' => '/provinces'], 'requires labels'];
        yield 'a cascade without its parameter' => [['url' => '/provinces', 'labels' => 'strtoupper', 'dependsOn' => 'region'], 'requires a url and a dependsParam'];
        yield 'a url on a text' => [['type' => 'text', 'url' => '/provinces'], 'A "text" control takes no "url"'];
    }
}
