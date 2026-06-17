<?php

namespace Fedale\GridviewBundle\Tests\Column;

use Fedale\GridviewBundle\Column\DataColumn;
use Fedale\GridviewBundle\Column\Type\DateType;
use Fedale\GridviewBundle\Column\Type\NumberType;
use Fedale\GridviewBundle\Grid\Gridview;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

class DataColumnPipelineTest extends TestCase
{
    private function column(string $attribute): DataColumn
    {
        return new DataColumn($this->createStub(Gridview::class), $attribute);
    }

    private function row(array $data): object
    {
        return new class($data) {
            public function __construct(public array $data)
            {
            }
        };
    }

    public function testTypePipelineFormatsWhenNoTwigFilter(): void
    {
        $column = $this->column('amount');
        $column->setColumnType(new NumberType());
        $column->setFormat(['decimals' => 2]);

        $out = $column->render($this->row(['amount' => 1234.56]), 0);

        $this->assertInstanceOf(Markup::class, $out);
        $this->assertStringContainsString('1.234,56', (string) $out);
    }

    /**
     * Regression: a column with both a data type and a legacy `twigFilter` must
     * return the RAW value so the twigFilter (e.g. date('d/m/Y')) still formats it.
     * Otherwise the type pre-formats and the twigFilter re-parses, throwing.
     */
    public function testTwigFilterReceivesRawValue(): void
    {
        $column = new DataColumn($this->createStub(Gridview::class), 'createdAt', "date('d/m/Y')");
        $column->setColumnType(new DateType());

        $date = new \DateTimeImmutable('2026-05-16');
        $out  = $column->render($this->row(['createdAt' => $date]), 0);

        // Raw DateTime returned, not the type-formatted "16/05/2026" string.
        $this->assertSame($date, $out);
    }

    public function testLegacyValueClosureStillShortCircuits(): void
    {
        $column = $this->column('x');
        $column->setColumnType(new NumberType());
        $column->value = fn (array $data) => '<b>' . $data['x'] . '</b>';

        $out = $column->render($this->row(['x' => 'hi']), 0);

        $this->assertSame('<b>hi</b>', $out);
    }
}
