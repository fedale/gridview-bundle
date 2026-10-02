<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
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
 * Covers the export column set, and how the keys reported by the page (the
 * columns the user left visible, in the order they arranged them) reduce and
 * reorder it.
 */
class GridviewExportColumnsTest extends TestCase
{
    private function createGridview(): Gridview
    {
        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $gridview = new Gridview($service, new ColumnFactory());
        $gridview->setSearchModel($this->createMock(SearchModelInterface::class));

        return $gridview;
    }

    /** @return string[] */
    private function attributes(array $columns): array
    {
        return array_map(static fn($column) => (string) $column->getAttribute(), $columns);
    }

    private function grid(array $columns): Gridview
    {
        $gridview = $this->createGridview();
        $gridview->setColumns($columns);

        return $gridview;
    }

    public function testWithoutReportedKeysTheWholeSetIsExported(): void
    {
        $gridview = $this->grid([['attribute' => 'id'], ['attribute' => 'name'], ['attribute' => 'email']]);

        $this->assertSame(['id', 'name', 'email'], $this->attributes($gridview->getExportColumns()));
        $this->assertSame(['id', 'name', 'email'], $this->attributes($gridview->getExportColumns(null)));
    }

    public function testColumnsTheGridNeverShowsStayOutOfTheDefaultSet(): void
    {
        // A slug, a rich-text body or an upload exists for the form alone; the
        // file of a grid that never displays them should not carry them either.
        $gridview = $this->grid([
            ['attribute' => 'name'],
            ['attribute' => 'slug', 'active' => ['inIndex' => false]],
            ['attribute' => 'content', 'active' => ['inIndex' => false]],
        ]);

        $this->assertSame(['name'], $this->attributes($gridview->getExportColumns()));
    }

    public function testFlaggingPutsAFormOnlyColumnBackInTheFile(): void
    {
        $gridview = $this->grid([
            ['attribute' => 'name', 'exportable' => true],
            ['attribute' => 'slug', 'exportable' => true, 'active' => ['inIndex' => false]],
        ]);

        $this->assertSame(['name', 'slug'], $this->attributes($gridview->getExportColumns()));
    }

    public function testReportedKeysSelectAndReorderTheColumns(): void
    {
        $gridview = $this->grid([['attribute' => 'id'], ['attribute' => 'name'], ['attribute' => 'email']]);

        // The user hid `id` and moved `email` before `name`.
        $this->assertSame(
            ['email', 'name'],
            $this->attributes($gridview->getExportColumns(['email', 'name']))
        );
    }

    public function testUnknownReportedKeysAreIgnored(): void
    {
        $gridview = $this->grid([['attribute' => 'id'], ['attribute' => 'name']]);

        $this->assertSame(['name'], $this->attributes($gridview->getExportColumns(['name', 'nope'])));
    }

    public function testARepeatedKeyDoesNotDuplicateTheColumn(): void
    {
        // Card and list items repeat their field keys, one set per record.
        $gridview = $this->grid([['attribute' => 'id'], ['attribute' => 'name']]);

        $this->assertSame(['name', 'id'], $this->attributes($gridview->getExportColumns(['name', 'id', 'name', 'id'])));
    }

    public function testAColumnOutsideTheIndexSurvivesTheReportedKeys(): void
    {
        // An export-only column has no cell on screen, so the page can never
        // report it — dropping it would make the column unreachable.
        $gridview = $this->grid([
            ['attribute' => 'id', 'exportable' => true],
            ['attribute' => 'name', 'exportable' => true],
            ['attribute' => 'internalCode', 'exportable' => true, 'active' => ['inIndex' => false]],
        ]);

        $this->assertSame(
            ['name', 'internalCode'],
            $this->attributes($gridview->getExportColumns(['name']))
        );
    }

    public function testFlaggedColumnsStillWinOverTheVisibleFallback(): void
    {
        $gridview = $this->grid([
            ['attribute' => 'id'],
            ['attribute' => 'name', 'exportable' => true],
            ['attribute' => 'email', 'exportable' => true],
        ]);

        // `id` is on screen and reported, but it is not part of the export set.
        $this->assertSame(
            ['email', 'name'],
            $this->attributes($gridview->getExportColumns(['id', 'email', 'name']))
        );
    }

    public function testAColumnHiddenFromTheCurrentViewIsNotExported(): void
    {
        // In the card view the page reports no `id` cell, so the file has none
        // either — the point of the whole mechanism.
        $gridview = $this->grid([
            ['attribute' => 'id', 'hideInViews' => ['card']],
            ['attribute' => 'name'],
            ['attribute' => 'summary', 'views' => ['card']],
        ]);

        $this->assertSame(
            ['name', 'summary'],
            $this->attributes($gridview->getExportColumns(['name', 'summary']))
        );
    }
}
