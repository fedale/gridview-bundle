<?php

namespace Fedale\GridviewBundle\Tests\Filter\Applier;

use Fedale\GridviewBundle\Filter\Applier\ChoiceFilterApplier;
use Fedale\GridviewBundle\Filter\Applier\RelationFilterApplier;
use Fedale\GridviewBundle\Tests\Support\CreatesQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

class RelationFilterApplierTest extends TestCase
{
    use CreatesQueryBuilderTrait;

    public function testSharesChoiceSemantics(): void
    {
        $applier = new RelationFilterApplier();
        $this->assertInstanceOf(ChoiceFilterApplier::class, $applier);

        $qb = $this->createTestQueryBuilder();
        $applier->apply($qb, 'l.id', ['7', '9']);

        $param = $qb->getParameters()->first();
        $this->assertSame(['7', '9'], $param->getValue());
        $this->assertSame(sprintf('l.id IN(:%s)', $param->getName()), $this->whereDql($qb));
    }
}
