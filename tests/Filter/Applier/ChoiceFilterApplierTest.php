<?php

namespace Fedale\GridviewBundle\Tests\Filter\Applier;

use Fedale\GridviewBundle\Filter\Applier\ChoiceFilterApplier;
use Fedale\GridviewBundle\Tests\Support\CreatesQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

class ChoiceFilterApplierTest extends TestCase
{
    use CreatesQueryBuilderTrait;

    private ChoiceFilterApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new ChoiceFilterApplier();
    }

    public function testScalarValueProducesEquality(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'l.id', '5');

        $param = $qb->getParameters()->first();
        $this->assertSame('5', $param->getValue());
        $this->assertSame(sprintf('l.id = :%s', $param->getName()), $this->whereDql($qb));
    }

    public function testArrayValueProducesIn(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'l.id', ['1', '3', '5']);

        $param = $qb->getParameters()->first();
        $this->assertSame(['1', '3', '5'], $param->getValue());
        $this->assertSame(sprintf('l.id IN(:%s)', $param->getName()), $this->whereDql($qb));
    }

    public function testEmptyEntriesAreStrippedFromArrays(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'l.id', ['', '2', '']);

        $this->assertSame(['2'], $qb->getParameters()->first()->getValue());
    }

    /**
     * @dataProvider blankValues
     */
    public function testBlankValuesAreSkipped(mixed $value): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'l.id', $value);

        $this->assertNull($qb->getDQLPart('where'));
    }

    public function blankValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'empty array' => [[]],
            'array of empties' => [['', '', '']],
        ];
    }
}
