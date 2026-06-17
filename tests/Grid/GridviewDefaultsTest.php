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

class GridviewDefaultsTest extends TestCase
{
    private SearchForm $searchForm;

    private function createGridview(): Gridview
    {
        $this->searchForm = new SearchForm(Forms::createFormFactory(), new RequestStack());

        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm($this->searchForm);
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $gridview = new Gridview($service, new ColumnFactory());
        $gridview->setSearchModel($this->createMock(SearchModelInterface::class));

        return $gridview;
    }

    public function testDefaultsAreCollectedWithMangledKeys(): void
    {
        $gridview = $this->createGridview();

        $gridview->setColumns([
            ['attribute' => 'active', 'filter' => ['type' => 'boolean', 'default' => '1']],
            ['attribute' => 'p.code', 'filter' => ['type' => 'text', 'default' => 'X']],
            ['attribute' => 'email', 'filter' => ['type' => 'text']],
        ]);

        $this->assertSame(
            ['active' => '1', 'p_code' => 'X'],
            $gridview->getDefaultFilterParams()
        );
    }

    public function testDefaultIsSetAsInitialFormData(): void
    {
        $gridview = $this->createGridview();

        $gridview->setColumns([
            ['attribute' => 'active', 'filter' => ['type' => 'boolean', 'default' => '1']],
            ['attribute' => 'createdAt', 'filter' => ['type' => 'date', 'default' => ['from' => '2026-01-01', 'to' => null]]],
        ]);

        $form = $this->searchForm->getModelType();
        $this->assertSame('1', $form->get('active')->getData());
        $this->assertSame('2026-01-01', $form->get('createdAt')->get('from')->getData());
        $this->assertNull($form->get('createdAt')->get('to')->getData());
    }

    public function testInvalidDefaultThrowsAtConfigurationTime(): void
    {
        $gridview = $this->createGridview();

        $this->expectException(\InvalidArgumentException::class);
        $gridview->setColumns([
            ['attribute' => 'active', 'filter' => ['type' => 'boolean', 'default' => 'maybe']],
        ]);
    }
}
