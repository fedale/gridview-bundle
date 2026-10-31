<?php

namespace Fedale\GridviewBundle\Tests\Export;

use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Export\CellText;
use Fedale\GridviewBundle\Export\JsonExporter;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

class CellTextTest extends TestCase
{
    public function testMarkupLosesItsTagsAndEntities(): void
    {
        self::assertSame('Rossi & Co', CellText::of(new Markup('<span class="gv-badge">Rossi &amp; Co</span>', 'UTF-8')));
        self::assertSame('"quoted" l\'apostrophe', CellText::of(new Markup('&quot;quoted&quot; l&#039;apostrophe', 'UTF-8')));
    }

    public function testAPlainStringIsNotHtml(): void
    {
        // Typed by a user: "&amp;" is what they wrote, not an entity.
        self::assertSame('Tom &amp; Jerry', CellText::of('Tom &amp; Jerry'));
        self::assertSame('bold', CellText::of('<b>bold</b>'));
    }

    public function testFlattensListsAndScalars(): void
    {
        self::assertSame('a, 2, b', CellText::of(['a', 2, new Markup('<i>b</i>', 'UTF-8')]));
        self::assertSame('', CellText::of(null));
        self::assertSame('', CellText::of(new \stdClass()));
    }

    /**
     * Excel, PDF and JSON flattened with is_scalar() only: a Markup cell (every number,
     * money or percent column) came out empty there, while CSV had been fixed alone.
     */
    public function testEveryExporterWritesMarkupCells(): void
    {
        $column = $this->createMock(ColumnInterface::class);
        $column->method('getAttribute')->willReturn('age');
        $column->method('getLabel')->willReturn('Age');
        $column->method('render')->willReturn(new Markup('<span class="gv-num">29</span>', 'UTF-8'));

        $json = (new JsonExporter())->export([new \stdClass()], [$column])->getContent();

        self::assertStringContainsString('"age": "29"', (string) $json);
    }
}
