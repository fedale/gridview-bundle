<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Grid\DetailView;
use Fedale\GridviewBundle\Row\Row;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Tests\Support\FakeDetailColumn;
use Fedale\GridviewBundle\Twig\OptionsExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\StringLoaderExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

class DetailViewTest extends TestCase
{
    private function detailView(Environment $twig): DetailView
    {
        return new DetailView(new GridviewService($twig), new ColumnFactory());
    }

    private function model(): Row
    {
        $row = new Row(0, 1);
        $row->data = ['name' => 'Acme', 'vat' => 'IT123', 'secret' => 'hidden-value'];

        return $row;
    }

    public function testRowsExposeLabelAndRenderedValuePerDataColumn(): void
    {
        $detail = $this->detailView(new Environment(new ArrayLoader()));
        $detail->setModel($this->model());
        $detail->addColumn(new FakeDetailColumn('name', 'Name'));
        $detail->addColumn(new FakeDetailColumn('vat', 'VAT'));

        $this->assertSame(
            [
                ['label' => 'Name', 'value' => 'Acme'],
                ['label' => 'VAT',  'value' => 'IT123'],
            ],
            $detail->rows()
        );
    }

    public function testActionAndStructuralColumnsAreExcluded(): void
    {
        $detail = $this->detailView(new Environment(new ArrayLoader()));
        $detail->setModel($this->model());
        $detail->addColumn(new FakeDetailColumn('name', 'Name'));
        // attribute === null → action/structural column, must be skipped
        $detail->addColumn(new FakeDetailColumn(null, 'Actions'));

        $this->assertSame(['Name'], array_column($detail->rows(), 'label'));
    }

    public function testHiddenColumnsIncludedByDefaultButFilteredByOnlyVisible(): void
    {
        $detail = $this->detailView(new Environment(new ArrayLoader()));
        $detail->setModel($this->model());
        $detail->addColumn(new FakeDetailColumn('name', 'Name'));
        $detail->addColumn(new FakeDetailColumn('secret', 'Secret', null, false /* not visible */));

        // default: every data column, hidden ones too
        $this->assertSame(['Name', 'Secret'], array_column($detail->rows(), 'label'));

        // opt-in: honour grid visibility
        $detail->setOptions(['onlyVisible' => true]);
        $this->assertSame(['Name'], array_column($detail->rows(), 'label'));
    }

    public function testRenderProducesKeyValueTable(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'FedaleGridview');
        $twig = new Environment($loader, ['autoescape' => 'html']);
        $twig->addExtension(new OptionsExtension());
        $twig->addExtension(new StringLoaderExtension());

        $detail = $this->detailView($twig);
        $detail->setModel($this->model());
        $detail->setAttributes(['class' => 'table table-bordered']);
        $detail->addColumn(new FakeDetailColumn('name', 'Name'));
        $detail->addColumn(new FakeDetailColumn('vat', 'VAT'));

        $html = $detail->render()->getContent();

        $this->assertStringContainsString('class="table table-bordered"', $html);
        $this->assertStringContainsString('<th scope="row">Name</th>', $html);
        $this->assertStringContainsString('Acme', $html);
        $this->assertStringContainsString('IT123', $html);
    }
}
