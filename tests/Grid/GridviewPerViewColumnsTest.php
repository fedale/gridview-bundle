<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Service\GridviewService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Covers the per-renderer column axis (`views` / `hideInViews`): which columns a
 * given data renderer draws, and what the axis deliberately leaves alone —
 * filters stay registered, and the write side is untouched.
 */
class GridviewPerViewColumnsTest extends TestCase
{
    private SearchForm $searchForm;

    private function createGridview(?string $renderer = null): Gridview
    {
        $this->searchForm = new SearchForm(Forms::createFormFactory(), new RequestStack());

        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm($this->searchForm);
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $gridview = new Gridview($service, new ColumnFactory());
        $gridview->setSearchModel($this->createMock(SearchModelInterface::class));

        if ($renderer !== null) {
            $gridview->setOptions(['display' => ['renderer' => $renderer]]);
        }

        return $gridview;
    }

    /** @return string[] */
    private function attributes(iterable $columns): array
    {
        $names = [];
        foreach ($columns as $column) {
            $names[] = (string) $column->getAttribute();
        }

        return $names;
    }

    public function testColumnsWithoutTheAxisAppearInEveryView(): void
    {
        $gridview = $this->createGridview('card');
        $gridview->setColumns([['attribute' => 'name'], ['attribute' => 'email']]);

        $this->assertSame(['name', 'email'], $this->attributes($gridview->getIndexColumns()));
        $this->assertSame(['name', 'email'], $this->attributes($gridview->getIndexColumns('table')));
    }

    public function testViewsRestrictTheColumnToTheNamedRenderers(): void
    {
        $gridview = $this->createGridview('card');
        $gridview->setColumns([
            ['attribute' => 'name'],
            ['attribute' => 'summary', 'views' => ['card']],
            ['attribute' => 'ref', 'views' => 'table'],
        ]);

        // The active renderer is `card`.
        $this->assertSame(['name', 'summary'], $this->attributes($gridview->getIndexColumns()));
        $this->assertSame(['name', 'ref'], $this->attributes($gridview->getIndexColumns('table')));
        $this->assertSame(['name'], $this->attributes($gridview->getIndexColumns('list')));
    }

    public function testHideInViewsKeepsTheColumnEverywhereElse(): void
    {
        $gridview = $this->createGridview('card');
        $gridview->setColumns([
            ['attribute' => 'name'],
            ['attribute' => 'createdAt', 'hideInViews' => ['card', 'list']],
        ]);

        $this->assertSame(['name'], $this->attributes($gridview->getIndexColumns()));
        $this->assertSame(['name'], $this->attributes($gridview->getIndexColumns('list')));
        $this->assertSame(['name', 'createdAt'], $this->attributes($gridview->getIndexColumns('table')));
    }

    public function testHideInViewsWinsOverViewsForTheSameName(): void
    {
        $gridview = $this->createGridview('card');
        $gridview->setColumns([
            ['attribute' => 'name', 'views' => ['card', 'table'], 'hideInViews' => ['card']],
        ]);

        $this->assertSame([], $this->attributes($gridview->getIndexColumns()));
        $this->assertSame(['name'], $this->attributes($gridview->getIndexColumns('table')));
    }

    public function testTheAxisComposesWithTheIndexContext(): void
    {
        $gridview = $this->createGridview('card');
        $gridview->setColumns([
            ['attribute' => 'name'],
            // Active in `index`, but scoped to a view that is not the active one.
            ['attribute' => 'ref', 'views' => ['table']],
            // Not in `index` at all, whatever the view says.
            ['attribute' => 'notes', 'views' => ['card'], 'active' => ['inIndex' => false]],
        ]);

        $this->assertSame(['name'], $this->attributes($gridview->getIndexColumns()));
        $this->assertSame(['name', 'ref'], $this->attributes($gridview->getIndexColumns('table')));
    }

    public function testAnExcludedColumnStaysRegisteredWithItsFilter(): void
    {
        $gridview = $this->createGridview('card');
        $gridview->setColumns([
            ['attribute' => 'name'],
            ['attribute' => 'ref', 'views' => ['table'], 'filter' => ['type' => 'text']],
        ]);

        // Absent from the rendered card view…
        $this->assertSame(['name'], $this->attributes($gridview->getIndexColumns()));
        // …yet still a registered column, and its filter still reaches the form.
        $this->assertSame(['name', 'ref'], $this->attributes($gridview->getColumns()));
        $this->assertTrue($this->searchForm->getModelType()->has('ref'));
    }

    public function testAnEmptyViewListIsRejected(): void
    {
        $gridview = $this->createGridview();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declares an empty "views" list');

        $gridview->setColumns([['attribute' => 'name', 'views' => []]]);
    }

    public function testAColumnOutsideAbstractColumnTakesPartInEveryView(): void
    {
        $gridview = $this->createGridview('card');
        $gridview->addColumn($this->bareColumn('legacy'));

        $this->assertSame(['legacy'], $this->attributes($gridview->getIndexColumns()));
    }

    /** A column implementing the interface directly, without the per-view axis. */
    private function bareColumn(string $attribute): ColumnInterface
    {
        $column = $this->createMock(ColumnInterface::class);
        $column->method('isActiveIn')->willReturn(true);
        $column->method('getAttribute')->willReturn($attribute);

        return $column;
    }
}
