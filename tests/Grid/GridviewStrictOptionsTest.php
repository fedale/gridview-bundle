<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Exception\InvalidGridOptionsException;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\GridviewBuilder;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Grid\Options\GridOptionsValidator;
use Fedale\GridviewBundle\Grid\TemplateCatalog;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

class GridviewStrictOptionsTest extends TestCase
{
    private function builder(?GridOptionsValidator $validator, array $config = []): GridviewBuilder
    {
        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $themes = new ThemeRegistry();

        return new GridviewBuilder(
            $service,
            new GridviewConfigRegistry($config),
            new ColumnFactory(),
            $themes,
            new ServiceLocator([]),
            optionsValidator: $validator,
        );
    }

    private function validator(): GridOptionsValidator
    {
        return new GridOptionsValidator(new ThemeRegistry(), null, new TemplateCatalog());
    }

    public function testWithoutAValidatorAnUnknownKeyIsIgnored(): void
    {
        $gridview = $this->builder(null)
            ->setId('customers')
            ->setOptions(['display' => ['renderr' => 'card']])
            ->renderGridview();

        $this->assertSame('table', $gridview->getRenderer());
    }

    public function testInStrictModeAnUnknownKeyThrows(): void
    {
        $builder = $this->builder($this->validator())
            ->setId('customers')
            ->setOptions(['display' => ['renderr' => 'card']]);

        $this->expectException(InvalidGridOptionsException::class);
        $this->expectExceptionMessage('display.renderr: Unknown key "renderr"');

        $builder->renderGridview();
    }

    public function testInStrictModeValidOptionsAndYamlDefaultsRender(): void
    {
        $config = ['gridviews' => ['customers' => ['display' => ['renderer' => 'list']]]];

        $gridview = $this->builder($this->validator(), $config)
            ->setId('customers')
            ->setOptions(['display' => ['title' => 'Customers', 'layout' => ['toolbar' => '{globalSearch} {spacer} {export}']]])
            ->renderGridview();

        $this->assertSame('list', $gridview->getRenderer());
        $this->assertSame('Customers', $gridview->getOptions()['display']['title']);
    }
}
