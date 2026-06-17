<?php

namespace Fedale\GridviewBundle\Tests\Filter\Applier;

use Fedale\GridviewBundle\Filter\Applier\NumberFilterApplier;
use Fedale\GridviewBundle\Tests\Support\CreatesQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

class NumberFilterApplierTest extends TestCase
{
    use CreatesQueryBuilderTrait;

    private NumberFilterApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new NumberFilterApplier();
    }

    public function testFromAndToProduceGteAndLte(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => '10', 'to' => '20.5']);

        $params = $qb->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(10, $params[0]->getValue());
        $this->assertSame(20.5, $params[1]->getValue());
        $this->assertSame(
            sprintf('p.price >= :%s AND p.price <= :%s', $params[0]->getName(), $params[1]->getName()),
            $this->whereDql($qb)
        );
    }

    public function testZeroIsAValidBound(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => '0', 'to' => '']);

        $params = $qb->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(0, $params[0]->getValue());
    }

    public function testNonNumericBoundsAreSkipped(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => 'abc', 'to' => '10x']);

        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testBlankOrNonArrayValuesAreSkipped(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => '', 'to' => null]);
        $this->applier->apply($qb, 'p.price', '10');
        $this->applier->apply($qb, 'p.price', null);

        $this->assertNull($qb->getDQLPart('where'));
    }

    /**
     * @dataProvider operatorExpressions
     */
    public function testOperatorSyntaxInFromBound(string $input, string $expectedDql, int|float $expectedValue): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => $input, 'to' => '']);

        $param = $qb->getParameters()->first();
        $this->assertSame($expectedValue, $param->getValue());
        $this->assertSame(sprintf($expectedDql, $param->getName()), $this->whereDql($qb));
    }

    public function operatorExpressions(): array
    {
        return [
            'equals'                => ['=10', 'p.price = :%s', 10],
            'not equals'            => ['!=10', 'p.price <> :%s', 10],
            'not equals diamond'    => ['<>10', 'p.price <> :%s', 10],
            'greater than'          => ['>5', 'p.price > :%s', 5],
            'greater or equal'      => ['>=5', 'p.price >= :%s', 5],
            'less than'             => ['<5', 'p.price < :%s', 5],
            'less or equal'         => ['<=5', 'p.price <= :%s', 5],
            'negative via operator' => ['>=-5', 'p.price >= :%s', -5],
            'decimal with comma'    => ['>2,5', 'p.price > :%s', 2.5],
            'spaces are tolerated'  => ['>= 5', 'p.price >= :%s', 5],
        ];
    }

    public function testRangeSyntaxProducesBetween(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => '1-5', 'to' => '']);

        $params = $qb->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(1, $params[0]->getValue());
        $this->assertSame(5, $params[1]->getValue());
        $this->assertSame(
            sprintf('p.price BETWEEN :%s AND :%s', $params[0]->getName(), $params[1]->getName()),
            $this->whereDql($qb)
        );
    }

    public function testRangeSyntaxNormalizesReversedBounds(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => '5-1', 'to' => '']);

        $params = $qb->getParameters();
        $this->assertSame(1, $params[0]->getValue());
        $this->assertSame(5, $params[1]->getValue());
    }

    public function testRangeSeparatorIsConfigurable(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => '1:5', 'to' => ''], ['range_separator' => ':']);

        $params = $qb->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(1, $params[0]->getValue());
        $this->assertSame(5, $params[1]->getValue());
    }

    public function testOperatorExpressionAndPlainToBoundCombine(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'p.price', ['from' => '>5', 'to' => '20']);

        $params = $qb->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(5, $params[0]->getValue());
        $this->assertSame(20, $params[1]->getValue());
        $this->assertSame(
            sprintf('p.price > :%s AND p.price <= :%s', $params[0]->getName(), $params[1]->getName()),
            $this->whereDql($qb)
        );
    }
}
