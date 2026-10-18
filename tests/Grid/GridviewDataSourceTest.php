<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\FilterApplierInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\DataProvider\DataSourceDataProvider;
use Fedale\GridviewBundle\DataProvider\DataSourceDataProviderFactory;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\Filter\Criteria\FilterCriteriaBuilderRegistry;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Grid\GridviewBuilder;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Pagination\Pagination;
use Fedale\GridviewBundle\Pagination\Strategy\NumericStrategy;
use Fedale\GridviewBundle\Pagination\Strategy\PaginatorStrategyRegistry;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Sort\Sort;
use Fedale\GridviewBundle\Tests\Fixtures\InMemoryGridDataSource;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/**
 * A grid powered by a GridDataSourceInterface, end to end through the builder:
 * the dataSource selection rules, the column hand-off, the search-model check
 * and the export.
 */
class GridviewDataSourceTest extends TestCase
{
    private InMemoryGridDataSource $source;

    private Sort $sort;

    private DataProviderInterface $entityProvider;

    /** @var array<string, mixed> */
    private array $rendered = [];

    private function builder(string $uri = '/pets', array $config = [], array $services = []): GridviewBuilder
    {
        $stack = new RequestStack();
        $stack->push(Request::create($uri));

        $this->source = new InMemoryGridDataSource([
            ['id' => 'a1', 'name' => 'Rex', 'age' => 3],
            ['id' => 'b2', 'name' => 'Fido', 'age' => 7],
            ['id' => 'c3', 'name' => 'Rocky', 'age' => 5],
        ]);
        $this->sort = new Sort($stack, $this->createStub(RouterInterface::class));
        $pagination = new Pagination($stack);

        $this->entityProvider = $this->createMock(DataProviderInterface::class);
        $this->entityProvider->method('getSort')->willReturn($this->sort);
        $this->entityProvider->method('getPagination')->willReturn($pagination);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $parameters): string {
            $this->rendered = $parameters;

            return '';
        });

        $service = new GridviewService($twig);
        $formFactory = Forms::createFormFactoryBuilder()->addExtension(new HttpFoundationExtension())->getFormFactory();
        $service->setSearchForm(new SearchForm($formFactory, $stack));
        $service->setRequest($stack);
        $service->setDataProvider($this->entityProvider);
        $service->setPaginatorStrategyRegistry(new PaginatorStrategyRegistry([new NumericStrategy()]));

        return new GridviewBuilder(
            $service,
            new GridviewConfigRegistry($config),
            new ColumnFactory(),
            new ThemeRegistry([]),
            new ServiceLocator([]),
            null,
            new ServiceLocator(array_map(fn($s) => static fn() => $s, $services)),
            new DataSourceDataProviderFactory(
                new EventDispatcher(),
                $stack,
                new FilterCriteriaBuilderRegistry(),
                $this->sort,
                $pagination,
                $this->createStub(SearchModelInterface::class),
            ),
        );
    }

    private function petGrid(GridviewBuilder $builder, array $dataConfig = [], array $options = []): Gridview
    {
        return $builder
            ->setId('record_pet')
            ->setSearchModel($this->createStub(SearchModelInterface::class))
            ->setDataProvider($dataConfig + ['dataSource' => $this->source, 'model' => ['object' => 'pet']])
            ->setColumns([
                ['attribute' => 'name', 'filter' => ['type' => 'text']],
                ['attribute' => 'age', 'filter' => ['type' => 'number']],
            ])
            ->setOptions($options)
            ->renderGridview();
    }

    public function testDataSourceInstanceIsWrappedInItsOwnProvider(): void
    {
        $builder = $this->builder();
        $gridview = $this->petGrid($builder);

        $provider = $gridview->getDataProvider();
        $this->assertInstanceOf(DataSourceDataProvider::class, $provider);
        $this->assertSame($this->source, $provider->getDataSource());
    }

    public function testDataSourceServiceIdIsResolvedThroughTheLocator(): void
    {
        $source = new InMemoryGridDataSource([]);
        $builder = $this->builder('/pets', [], ['app.pet_source' => $source]);

        $gridview = $this->petGrid($builder, ['dataSource' => 'app.pet_source']);

        $this->assertSame($source, $gridview->getDataProvider()->getDataSource());
    }

    public function testUnknownDataSourceIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Gridview "record_pet" is configured with dataSource "app\.missing"/');

        $this->petGrid($this->builder(), ['dataSource' => 'app.missing']);
    }

    public function testDataSourceAndAPerGridDataProviderConflict(): void
    {
        $builder = $this->builder('/pets', ['gridviews' => ['record_pet' => ['dataProvider' => 'app.provider']]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gridview "record_pet" declares both a dataSource and a dataProvider.');

        $this->petGrid($builder);
    }

    public function testDataSourceOverridesTheDefaultDataProvider(): void
    {
        $gridview = $this->petGrid($this->builder('/pets', ['defaults' => ['dataProvider' => 'app.global_provider']]));

        $this->assertInstanceOf(DataSourceDataProvider::class, $gridview->getDataProvider());
    }

    public function testDqlAppliersAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Declare criteria builders in "search\.builders" instead\./');

        $this->petGrid($this->builder(), ['search' => ['appliers' => ['text' => $this->createStub(FilterApplierInterface::class)]]]);
    }

    public function testColumnFiltersWithoutASearchModelAreRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Gridview "record_pet" declares filters (name) but has no search model, so they were dropped. Call setSearchModel() before setColumns().');

        $this->builder()
            ->setId('record_pet')
            ->setDataProvider(['dataSource' => $this->source, 'model' => ['object' => 'pet']])
            ->setColumns([['attribute' => 'name', 'filter' => ['type' => 'text']]])
            ->renderGridview();
    }

    public function testSearchFieldsWithoutASearchModelAreRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/declares filters \(species\)/');

        $this->builder()
            ->setId('record_pet')
            ->setDataProvider([
                'dataSource' => $this->source,
                'model' => ['object' => 'pet'],
                'search' => ['fields' => ['species' => 'text']],
            ])
            ->setColumns(['name'])
            ->renderGridview();
    }

    public function testAGridWithoutFiltersNeedsNoSearchModel(): void
    {
        $gridview = $this->builder()
            ->setId('record_pet')
            ->setDataProvider(['dataSource' => $this->source, 'model' => ['object' => 'pet']])
            ->setColumns(['name'])
            ->renderGridview();

        $this->assertInstanceOf(DataSourceDataProvider::class, $gridview->getDataProvider());
    }

    public function testAnEntityGridWithoutASearchModelIsUnaffected(): void
    {
        $gridview = $this->builder()
            ->setId('customer')
            ->setDataProvider(['model' => 'App\\Entity\\Customer'])
            ->setColumns([['attribute' => 'name', 'filter' => ['type' => 'text']]])
            ->renderGridview();

        $this->assertSame($this->entityProvider, $gridview->getDataProvider());
    }

    public function testColumnFiltersAndSearchFieldsReachTheSource(): void
    {
        $gridview = $this->petGrid(
            $this->builder('/pets?fedaleForm[name]=r&fedaleForm[age]=>4&fedaleForm[species]=eq dog'),
            ['search' => ['fields' => ['species' => 'text']]],
        );

        $gridview->getExportRows();

        $this->assertEquals([
            new FilterCriterion('name', FilterOperator::Contains, 'r', true),
            new FilterCriterion('age', FilterOperator::Gt, 4),
            new FilterCriterion('species', FilterOperator::Eq, 'dog'),
        ], $this->source->lastQuery('fetch')->criteria);
    }

    public function testSortableColumnsSortOnTheirOwnAttributeWithoutASortMap(): void
    {
        $gridview = $this->petGrid($this->builder('/pets?sort=-age'));

        $rows = $gridview->getExportRows();

        $this->assertTrue($this->sort->hasAttribute('name'));
        $this->assertTrue($this->sort->hasAttribute('age'));
        $this->assertSame(['Fido', 'Rocky', 'Rex'], array_map(fn($row) => $row->data['name'], $rows->toArray()));
    }

    public function testAnExplicitSortMapWins(): void
    {
        $gridview = $this->petGrid($this->builder('/pets?sort=name'), ['sort' => ['map' => ['name' => ['asc' => ['age' => 'asc'], 'desc' => ['age' => 'desc']]]]]);

        $rows = $gridview->getExportRows();

        $this->assertFalse($this->sort->hasAttribute('age'));
        $this->assertSame(['Rex', 'Rocky', 'Fido'], array_map(fn($row) => $row->data['name'], $rows->toArray()));
    }

    public function testAnEntityGridGetsNoIdentitySortMap(): void
    {
        $gridview = $this->builder('/customers')
            ->setId('customer')
            ->setDataProvider(['model' => 'App\\Entity\\Customer'])
            ->setColumns(['name'])
            ->renderGridview();
        $this->entityProvider->method('getAllData')->willReturn([]);

        $gridview->getExportRows();

        $this->assertFalse($this->sort->hasAttribute('name'));
    }

    public function testExportAppliesTheGlobalSearchOfADataSourceGrid(): void
    {
        $gridview = $this->petGrid($this->builder('/pets?fedaleForm[_q]=ro'), [], ['behavior' => ['globalSearch' => ['name']]]);

        $rows = $gridview->getExportRows();

        $this->assertSame('ro', $this->source->lastQuery('fetch')->searchTerm);
        $this->assertSame(['Rocky'], array_map(fn($row) => $row->data['name'], $rows->toArray()));
    }

    public function testExportAppliesTheGlobalSearchOfAnEntityGridOnce(): void
    {
        $builder = $this->builder('/customers?fedaleForm[_q]=acme');
        $this->entityProvider->expects($this->once())->method('applyGlobalSearch')->with(['name'], 'acme');
        $this->entityProvider->method('getData')->willReturn([]);
        $this->entityProvider->method('getAllData')->willReturn([]);

        $gridview = $builder
            ->setId('customer')
            ->setDataProvider(['model' => 'App\\Entity\\Customer'])
            ->setColumns(['name'])
            ->setOptions(['behavior' => ['globalSearch' => ['name']]])
            ->renderGridview();

        // The list, then the export of the same grid: one condition, not two.
        $gridview->renderGrid('list.html.twig');
        $gridview->getExportRows();
    }

    public function testRenderingAGridOverADataSource(): void
    {
        $gridview = $this->petGrid($this->builder('/pets?fedaleForm[age]=>2&sort=name&per-page=2'));

        $gridview->renderGrid('list.html.twig');

        $this->assertFalse($gridview->dataLoadFailed);
        $this->assertSame(['Fido', 'Rex'], array_map(fn($row) => $row->data['name'], $this->rendered['models']->toArray()));
        $this->assertSame(3, $this->rendered['pagination']->getTotalCount());
        $this->assertSame(2, $this->rendered['pagination']->getPageCount());
        $this->assertTrue($this->rendered['form']->offsetExists('name'), 'the column filter is on the form');
    }
}
