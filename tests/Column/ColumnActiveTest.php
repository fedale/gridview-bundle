<?php

namespace Fedale\GridviewBundle\Tests\Column;

use Fedale\GridviewBundle\Column\DataColumn;
use Fedale\GridviewBundle\Grid\Gridview;
use PHPUnit\Framework\TestCase;

/**
 * Covers the per-context `active` switch: bool, false, partial array (omitted
 * keys default to true) and Closure forms, plus the isActive()/isActiveIn() reads.
 */
class ColumnActiveTest extends TestCase
{
    private function column(): DataColumn
    {
        return new DataColumn($this->createStub(Gridview::class), 'attr');
    }

    public function testDefaultsToActiveEverywhere(): void
    {
        $column = $this->column();

        $this->assertTrue($column->isActive());
        foreach (['index', 'view', 'create', 'update'] as $ctx) {
            $this->assertTrue($column->isActiveIn($ctx));
        }
    }

    public function testFalseDisablesEveryContext(): void
    {
        $column = $this->column();
        $column->setActive(false);

        $this->assertFalse($column->isActive());
        foreach (['index', 'view', 'create', 'update'] as $ctx) {
            $this->assertFalse($column->isActiveIn($ctx));
        }
    }

    public function testPartialArrayOnlyDisablesListedContexts(): void
    {
        $column = $this->column();
        $column->setActive(['inIndex' => false]);

        // Still registered because it is active somewhere.
        $this->assertTrue($column->isActive());
        $this->assertFalse($column->isActiveIn('index'));
        $this->assertTrue($column->isActiveIn('view'));
        $this->assertTrue($column->isActiveIn('create'));
        $this->assertTrue($column->isActiveIn('update'));
    }

    public function testArrayMapsAllFourKeys(): void
    {
        $column = $this->column();
        $column->setActive([
            'inIndex'  => true,
            'inView'   => false,
            'inCreate' => true,
            'inUpdate' => false,
        ]);

        $this->assertTrue($column->isActiveIn('index'));
        $this->assertFalse($column->isActiveIn('view'));
        $this->assertTrue($column->isActiveIn('create'));
        $this->assertFalse($column->isActiveIn('update'));
    }

    public function testClosureCanReturnArray(): void
    {
        $column = $this->column();
        $column->setActive(static fn () => ['inIndex' => false]);

        $this->assertFalse($column->isActiveIn('index'));
        $this->assertTrue($column->isActiveIn('update'));
    }
}
