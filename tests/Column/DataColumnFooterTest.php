<?php

namespace Fedale\GridviewBundle\Tests\Column;

use Fedale\GridviewBundle\Column\CheckboxColumn;
use Fedale\GridviewBundle\Column\DataColumn;
use Fedale\GridviewBundle\Column\FooterAggregate;
use Fedale\GridviewBundle\Column\Type\CurrencyType;
use Fedale\GridviewBundle\Column\Type\NumberType;
use Fedale\GridviewBundle\Grid\Gridview;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

class DataColumnFooterTest extends TestCase
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

    public function testStringAggregateBecomesDatasetExpression(): void
    {
        $column = $this->column('qty');
        $column->setFooter('sum');

        $this->assertTrue($column->hasFooter());
        $this->assertSame('SUM(e.qty)', $column->footerDatasetExpression('e'));
    }

    public function testArrayAggregateWithScopeDefault(): void
    {
        $column = $this->column('qty');
        $column->setFooter(['agg' => FooterAggregate::Avg]);

        $this->assertSame('AVG(e.qty)', $column->footerDatasetExpression('e'));
    }

    public function testRawExpressionIsPassedThrough(): void
    {
        $column = $this->column('total');
        $column->setFooter(['expr' => 'SUM(e.qty * e.price)']);

        $this->assertSame('SUM(e.qty * e.price)', $column->footerDatasetExpression('e'));
    }

    public function testPageScopeHasNoDatasetExpression(): void
    {
        $column = $this->column('qty');
        $column->setFooter(['agg' => 'sum', 'scope' => 'page']);

        $this->assertNull($column->footerDatasetExpression('e'));
    }

    public function testRelationAttributeFallsBackToPage(): void
    {
        $column = $this->column('order.qty');
        $column->setFooter('sum');

        // A dotted path needs a join we don't build, so it can't be batched.
        $this->assertNull($column->footerDatasetExpression('e'));
    }

    public function testDatasetValueIsFormattedThroughColumnType(): void
    {
        $column = $this->column('qty');
        $column->setColumnType(new NumberType());
        $column->setFooter('sum');

        $out = $column->renderFooter(1234, []);

        $this->assertInstanceOf(Markup::class, $out);
        $this->assertStringContainsString('1.234', (string) $out);
    }

    public function testPageScopeReducesOverRows(): void
    {
        $column = $this->column('qty');
        $column->setColumnType(new NumberType());
        $column->setFooter(['agg' => 'sum', 'scope' => 'page']);

        $rows = [$this->row(['qty' => 10]), $this->row(['qty' => 5]), $this->row(['qty' => 2])];
        $out  = $column->renderFooter(null, $rows);

        $this->assertStringContainsString('17', (string) $out);
    }

    public function testCountRendersPlainIntegerEvenForCurrencyColumn(): void
    {
        $column = $this->column('price');
        $column->setColumnType(new CurrencyType());
        $column->setFooter('count');

        $out = $column->renderFooter(5, []);

        $this->assertSame('<span class="gv-num">5</span>', (string) $out);
    }

    public function testLiteralLabelOnStructuralColumn(): void
    {
        $column = new CheckboxColumn($this->createStub(Gridview::class));
        $column->setFooter('Total');

        $this->assertTrue($column->hasFooter());
        $this->assertSame('Total', $column->renderFooter(null, []));
    }

    public function testClosureFooterReceivesDatasetValueAndRows(): void
    {
        $column = $this->column('qty');
        $rows   = [$this->row(['qty' => 1])];
        $column->setFooter(fn ($value, $r, $col) => sprintf('%d over %d rows', $value, \count($r)));

        $this->assertSame('42 over 1 rows', $column->renderFooter(42, $rows));
    }
}
