<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Filter\FilterType;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Service\GridviewService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * `search.fields` declares filter controls that have no column of their own —
 * the case a search box detached from the grid needs.
 */
class GridviewSearchFieldsTest extends TestCase
{
    private SearchForm $searchForm;

    private function createGridview(bool $withSearchModel = true): Gridview
    {
        $this->searchForm = new SearchForm(Forms::createFormFactory(), new RequestStack());

        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm($this->searchForm);
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $gridview = new Gridview($service, new ColumnFactory());
        $gridview->setId('customer');
        if ($withSearchModel) {
            $gridview->setSearchModel($this->createMock(SearchModelInterface::class));
        }

        return $gridview;
    }

    public function testFieldWithoutColumnIsRegisteredOnTheSearchForm(): void
    {
        $gridview = $this->createGridview();

        $gridview->setColumns([['attribute' => 'email', 'filter' => ['type' => 'text']]]);
        $gridview->setDataProviderOptions([
            'model'  => 'App\\Entity\\Customer',
            'search' => [
                'map'    => ['country' => ['choice', 'c.country']],
                'fields' => ['country' => ['type' => 'choice', 'options' => ['choices' => ['IT' => 'IT']]]],
            ],
        ]);

        $form = $this->searchForm->getModelType();
        $this->assertTrue($form->has('country'), 'the column-less field is on the form');
        $this->assertTrue($form->has('email'), 'the column-backed field is untouched');
    }

    public function testFieldNameIsMangledLikeAColumnAttribute(): void
    {
        $gridview = $this->createGridview();

        $gridview->setDataProviderOptions([
            'search' => ['fields' => ['t.name' => ['type' => 'text']]],
        ]);

        // `t.name` travels as fedaleForm[t_name], same rule SearchForm::addFilter()
        // applies to column attributes.
        $this->assertTrue($this->searchForm->getModelType()->has('t_name'));
    }

    public function testShorthandAndEnumTypesAreAccepted(): void
    {
        $gridview = $this->createGridview();

        $gridview->setDataProviderOptions([
            'search' => ['fields' => [
                'note'   => 'text',
                'active' => FilterType::Boolean,
            ]],
        ]);

        $form = $this->searchForm->getModelType();
        $this->assertTrue($form->has('note'));
        $this->assertTrue($form->has('active'));
    }

    public function testDeclaredDefaultIsCollectedAndPrefillsTheControl(): void
    {
        $gridview = $this->createGridview();

        $gridview->setDataProviderOptions([
            'search' => ['fields' => ['active' => ['type' => 'boolean', 'default' => '1']]],
        ]);

        $this->assertSame(['active' => '1'], $gridview->getDefaultFilterParams());
        $this->assertSame('1', $this->searchForm->getModelType()->get('active')->getData());
    }

    /**
     * The defaults are read by initializeDataProvider() before it even looks at
     * `search.map`, which is why the fields are registered in
     * setDataProviderOptions() and not later. Guards that ordering.
     */
    public function testDeclaredDefaultReachesTheDataProvider(): void
    {
        $this->searchForm = new SearchForm(Forms::createFormFactory(), new RequestStack());

        $provider = $this->createMock(DataProviderInterface::class);
        $provider->expects($this->once())
            ->method('setDefaultParams')
            ->with(['active' => '1']);
        $provider->method('getAllData')->willReturn([]);

        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm($this->searchForm);
        $service->setDataProvider($provider);

        $gridview = new Gridview($service, new ColumnFactory());
        $gridview->setId('customer');
        $gridview->setSearchModel($this->createMock(SearchModelInterface::class));
        $gridview->setDataProviderOptions([
            'model'  => 'App\\Entity\\Customer',
            'search' => ['fields' => ['active' => ['type' => 'boolean', 'default' => '1']]],
        ]);

        // getExportRows() is the cheapest public entry point into
        // initializeDataProvider() (renderGrid() would need a real Twig).
        $gridview->getExportRows();
    }

    public function testColumnWinsOverASearchFieldOfTheSameName(): void
    {
        $gridview = $this->createGridview();

        $gridview->setColumns([
            ['attribute' => 'country', 'filter' => ['type' => 'text', 'default' => 'IT']],
        ]);
        $gridview->setDataProviderOptions([
            'search' => ['fields' => ['country' => ['type' => 'boolean', 'default' => '1']]],
        ]);

        // The column's control and its default both survive: Form::add() replaces
        // silently, so the precedence has to be an explicit guard.
        $form = $this->searchForm->getModelType();
        $this->assertSame('IT', $form->get('country')->getData());
        $this->assertSame(['country' => 'IT'], $gridview->getDefaultFilterParams());
    }

    public function testColumnStillWinsWhenDeclaredAfterTheSearchField(): void
    {
        $gridview = $this->createGridview();

        // Reversed order: the builder never does this, but setDataProviderOptions()
        // is public API and the precedence must not depend on the call order.
        $gridview->setDataProviderOptions([
            'search' => ['fields' => ['country' => ['type' => 'boolean', 'default' => '1']]],
        ]);
        $gridview->setColumns([
            ['attribute' => 'country', 'filter' => ['type' => 'text', 'default' => 'IT']],
        ]);

        $this->assertSame('IT', $this->searchForm->getModelType()->get('country')->getData());
        $this->assertSame(['country' => 'IT'], $gridview->getDefaultFilterParams());
    }

    public function testUnknownFilterTypeThrowsWithTheAvailableTypes(): void
    {
        $gridview = $this->createGridview();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/search field "tier".*"facet_tier".*text, boolean, date, number, choice, relation/s');

        $gridview->setDataProviderOptions([
            'search' => ['fields' => ['tier' => ['type' => 'facet_tier']]],
        ]);
    }

    public function testNothingIsRegisteredWithoutASearchModel(): void
    {
        $gridview = $this->createGridview(withSearchModel: false);

        $gridview->setDataProviderOptions([
            'search' => ['fields' => ['country' => ['type' => 'choice']]],
        ]);

        $this->assertFalse($this->searchForm->getModelType()->has('country'));
    }

    public function testMapAloneRegistersNoControl(): void
    {
        $gridview = $this->createGridview();

        // A `map` entry with no field stays a URL-driven filter — it must not
        // grow a control of its own.
        $gridview->setDataProviderOptions([
            'search' => ['map' => ['country' => ['choice', 'c.country']]],
        ]);

        $this->assertFalse($this->searchForm->getModelType()->has('country'));
    }
}
