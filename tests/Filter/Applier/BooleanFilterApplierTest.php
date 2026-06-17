<?php

namespace Fedale\GridviewBundle\Tests\Filter\Applier;

use Doctrine\DBAL\Types\Types;
use Fedale\GridviewBundle\Filter\Applier\BooleanFilterApplier;
use Fedale\GridviewBundle\Tests\Support\CreatesQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

class BooleanFilterApplierTest extends TestCase
{
    use CreatesQueryBuilderTrait;

    private BooleanFilterApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new BooleanFilterApplier();
    }

    /**
     * @dataProvider truthyValues
     */
    public function testTruthyValuesBindTrueWithBooleanType(mixed $value): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.active', $value);

        $param = $qb->getParameters()->first();
        $this->assertTrue($param->getValue());
        $this->assertSame(Types::BOOLEAN, $param->getType());
        $this->assertSame(sprintf('c.active = :%s', $param->getName()), $this->whereDql($qb));
    }

    public function truthyValues(): array
    {
        return ['string 1' => ['1'], 'int 1' => [1], 'true' => [true]];
    }

    public function testStringZeroIsAValidFalseValueNotBlank(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.active', '0');

        $param = $qb->getParameters()->first();
        $this->assertFalse($param->getValue());
        $this->assertSame(Types::BOOLEAN, $param->getType());
    }

    /**
     * @dataProvider skippedValues
     */
    public function testNonBooleanValuesAreSkipped(mixed $value): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.active', $value);

        $this->assertNull($qb->getDQLPart('where'));
    }

    public function skippedValues(): array
    {
        return ['null' => [null], 'empty string' => [''], 'arbitrary string' => ['abc'], 'array' => [['1']]];
    }
}
