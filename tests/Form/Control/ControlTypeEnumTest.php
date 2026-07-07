<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Form\Control\ControlType;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use PHPUnit\Framework\TestCase;

class ControlTypeEnumTest extends TestCase
{
    /** The ControlType enum and the control registry must list the same type names. */
    public function testEnumMatchesControlTypeRegistry(): void
    {
        $enumValues     = array_map(fn (ControlType $c): string => $c->value, ControlType::cases());
        $registryValues = $this->registryTypeNames();

        sort($enumValues);
        sort($registryValues);

        $this->assertSame($registryValues, $enumValues);
    }

    /** Every enum case resolves in the registry. */
    public function testEveryEnumCaseIsRegistered(): void
    {
        $registry = new ControlTypeRegistry();

        foreach (ControlType::cases() as $case) {
            $this->assertTrue(
                $registry->has($case->value),
                sprintf('ControlType::%s ("%s") is not registered.', $case->name, $case->value)
            );
        }
    }

    /** @return string[] */
    private function registryTypeNames(): array
    {
        $property = new \ReflectionProperty(ControlTypeRegistry::class, 'types');

        return array_keys($property->getValue(new ControlTypeRegistry()));
    }
}
