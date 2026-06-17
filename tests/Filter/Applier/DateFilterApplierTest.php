<?php

namespace Fedale\GridviewBundle\Tests\Filter\Applier;

use Fedale\GridviewBundle\Filter\Applier\DateFilterApplier;
use Fedale\GridviewBundle\Tests\Support\CreatesQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

class DateFilterApplierTest extends TestCase
{
    use CreatesQueryBuilderTrait;

    private DateFilterApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new DateFilterApplier();
    }

    public function testFromAndToProduceGteAndLteWithEndOfDay(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.createdAt', ['from' => '2026-01-01', 'to' => '2026-02-01']);

        $params = $qb->getParameters();
        $this->assertCount(2, $params);

        $this->assertEquals(new \DateTime('2026-01-01 00:00:00'), $params[0]->getValue());
        $this->assertEquals(new \DateTime('2026-02-01 23:59:59'), $params[1]->getValue());

        $this->assertSame(
            sprintf('c.createdAt >= :%s AND c.createdAt <= :%s', $params[0]->getName(), $params[1]->getName()),
            $this->whereDql($qb)
        );
    }

    public function testEndOfDayCanBeDisabled(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.createdAt', ['to' => '2026-02-01'], ['end_of_day' => false]);

        $this->assertEquals(new \DateTime('2026-02-01 00:00:00'), $qb->getParameters()->first()->getValue());
    }

    public function testOnlyFromBound(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.createdAt', ['from' => '2026-01-01', 'to' => '']);

        $params = $qb->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(sprintf('c.createdAt >= :%s', $params[0]->getName()), $this->whereDql($qb));
    }

    public function testNonIsoBoundsAreSkipped(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.createdAt', ['from' => '01/02/2026', 'to' => '2026-13-99garbage']);

        $this->assertNull($qb->getDQLPart('where'));
    }

    /**
     * @dataProvider skippedValues
     */
    public function testBlankOrNonArrayValuesAreSkipped(mixed $value): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.createdAt', $value);

        $this->assertNull($qb->getDQLPart('where'));
    }

    public function skippedValues(): array
    {
        return [
            'null' => [null],
            'plain string' => ['2026-01-01'],
            'all-empty array' => [['from' => '', 'to' => '']],
            'empty array' => [[]],
        ];
    }
}
