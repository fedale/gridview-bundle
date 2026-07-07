<?php

namespace Fedale\GridviewBundle\Tests\Filter;

use Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry;
use Fedale\GridviewBundle\Filter\FilterType;
use PHPUnit\Framework\TestCase;

class FilterTypeEnumTest extends TestCase
{
    /** The FilterType enum and the applier registry must list the same type names. */
    public function testEnumMatchesFilterApplierRegistry(): void
    {
        $enumValues     = array_map(fn (FilterType $c): string => $c->value, FilterType::cases());
        $registryValues = $this->registryTypeNames();

        sort($enumValues);
        sort($registryValues);

        $this->assertSame($registryValues, $enumValues);
    }

    /** @return string[] */
    private function registryTypeNames(): array
    {
        $property = new \ReflectionProperty(FilterApplierRegistry::class, 'types');

        return array_keys($property->getValue(new FilterApplierRegistry()));
    }
}
