<?php

namespace Fedale\GridviewBundle\Tests\Export;

use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Export\CsvExporter;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

class CsvExporterTest extends TestCase
{
    public function testExportsPlainScalarValues(): void
    {
        $csv = $this->export([$this->column('name', 'Name', 'Ada')]);

        self::assertStringContainsString("Name\nAda\n", $csv);
    }

    /**
     * A column type's render() (NumberType/MoneyType/PercentType) wraps its
     * output in a Twig\Markup for HTML escaping. Before the fix, flatten()'s
     * is_scalar() check was false for it (an object) and fell through to an
     * empty string, silently dropping every numeric-typed column from CSV
     * exports regardless of data source.
     */
    public function testExportsMarkupWrappedValues(): void
    {
        $csv = $this->export([$this->column('age', 'Age', new Markup('<span class="gv-num">29</span>', 'UTF-8'))]);

        self::assertStringContainsString("Age\n29\n", $csv);
    }

    private function export(array $columns): string
    {
        $response = (new CsvExporter())->export([new \stdClass()], $columns);

        // Strip the UTF-8 BOM the exporter prepends for Excel.
        return ltrim($response->getContent(), "\xEF\xBB\xBF");
    }

    private function column(string $attribute, string $label, mixed $renderedValue): ColumnInterface
    {
        $column = $this->createMock(ColumnInterface::class);
        $column->method('getAttribute')->willReturn($attribute);
        $column->method('getLabel')->willReturn($label);
        $column->method('render')->willReturn($renderedValue);

        return $column;
    }
}
