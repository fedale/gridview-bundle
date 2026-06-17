<?php

namespace Fedale\GridviewBundle\Tests\Filter\Applier;

use Doctrine\ORM\QueryBuilder;
use Fedale\GridviewBundle\Contract\FilterApplierInterface;
use Fedale\GridviewBundle\Filter\Applier\BooleanFilterApplier;
use Fedale\GridviewBundle\Filter\Applier\ChoiceFilterApplier;
use Fedale\GridviewBundle\Filter\Applier\DateFilterApplier;
use Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry;
use Fedale\GridviewBundle\Filter\Applier\NumberFilterApplier;
use Fedale\GridviewBundle\Filter\Applier\RelationFilterApplier;
use Fedale\GridviewBundle\Filter\Applier\TextFilterApplier;
use PHPUnit\Framework\TestCase;

class FilterApplierRegistryTest extends TestCase
{
    public function testBuiltInTypesResolve(): void
    {
        $registry = new FilterApplierRegistry();

        $this->assertInstanceOf(TextFilterApplier::class, $registry->get('text'));
        $this->assertInstanceOf(BooleanFilterApplier::class, $registry->get('boolean'));
        $this->assertInstanceOf(DateFilterApplier::class, $registry->get('date'));
        $this->assertInstanceOf(NumberFilterApplier::class, $registry->get('number'));
        $this->assertInstanceOf(ChoiceFilterApplier::class, $registry->get('choice'));
        $this->assertInstanceOf(RelationFilterApplier::class, $registry->get('relation'));
    }

    public function testInstancesAreCached(): void
    {
        $registry = new FilterApplierRegistry();

        $this->assertSame($registry->get('text'), $registry->get('text'));
    }

    public function testCustomTypeCanBeRegistered(): void
    {
        $registry = new FilterApplierRegistry();
        $custom = new class implements FilterApplierInterface {
            public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
            {
            }
        };

        $registry->register('custom', $custom);

        $this->assertSame($custom, $registry->get('custom'));
    }

    public function testBuiltInTypeCanBeOverridden(): void
    {
        $registry = new FilterApplierRegistry();
        $custom = new class implements FilterApplierInterface {
            public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
            {
            }
        };

        $registry->register('text', $custom);

        $this->assertSame($custom, $registry->get('text'));
    }

    public function testUnknownTypeThrows(): void
    {
        $registry = new FilterApplierRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown filter applier type "nope"');
        $registry->get('nope');
    }
}
