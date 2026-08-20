<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Grid\GridviewBuilder;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * GridviewBuilder::setDataProvider() only buffers the config — the push to the
 * Gridview happens in renderGridview(), after the columns are built. Anything
 * that needs the data provider config therefore cannot live in setColumns().
 * This is the constraint that puts `search.fields` registration in
 * Gridview::setDataProviderOptions(); these tests pin it down.
 */
class GridviewLifecycleOrderTest extends TestCase
{
    private SearchForm $searchForm;

    private function createBuilder(): GridviewBuilder
    {
        $this->searchForm = new SearchForm(Forms::createFormFactory(), new RequestStack());

        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm($this->searchForm);
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        return new GridviewBuilder(
            $service,
            new GridviewConfigRegistry([]),
            new ColumnFactory(),
            new ThemeRegistry([]),
            new ServiceLocator([]),
        );
    }

    public function testDataProviderOptionsAreNotVisibleDuringSetColumns(): void
    {
        $builder = $this->createBuilder();
        $seen = [];

        $builder
            ->setId('customer')
            ->setSearchModel($this->createMock(SearchModelInterface::class))
            ->setDataProvider(['model' => 'App\\Entity\\Customer'])
            ->setColumns([
                [
                    'attribute' => 'email',
                    // A closure spec value is resolved by the ColumnFactory, i.e.
                    // in the middle of setColumns() — the observation point.
                    'active' => function () use (&$seen) {
                        $seen[] = 'during setColumns';

                        return true;
                    },
                ],
            ]);

        $this->assertSame(['during setColumns'], $seen, 'the column was actually built');

        $gridview = $builder->renderGridview();
        $this->assertSame('App\\Entity\\Customer', $gridview->getDataClass());
    }

    public function testSearchFieldsAreRegisteredByTheTimeRenderGridviewReturns(): void
    {
        $gridview = $this->createBuilder()
            ->setId('customer')
            ->setSearchModel($this->createMock(SearchModelInterface::class))
            ->setDataProvider([
                'model'  => 'App\\Entity\\Customer',
                'search' => ['fields' => ['country' => ['type' => 'text']]],
            ])
            ->setColumns([['attribute' => 'email', 'filter' => ['type' => 'text']]])
            ->renderGridview();

        $this->assertInstanceOf(Gridview::class, $gridview);
        $this->assertTrue($this->searchForm->getModelType()->has('country'));
    }

    /**
     * setDataProviderOptions() is skipped entirely when setDataProvider() was
     * never called (GridviewBuilder guards on a non-empty buffer), so a grid
     * without a data provider must not blow up over `search.fields`.
     */
    public function testNoDataProviderMeansNoSearchFields(): void
    {
        $this->createBuilder()
            ->setId('customer')
            ->setSearchModel($this->createMock(SearchModelInterface::class))
            ->setColumns([['attribute' => 'email', 'filter' => ['type' => 'text']]])
            ->renderGridview();

        $form = $this->searchForm->getModelType();
        $this->assertTrue($form->has('email'));
        $this->assertFalse($form->has('country'));
    }
}
